<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\LanInterfaces;
use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Notifications;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Modules\PbxExtensionUtils;
use Throwable;

require_once 'Globals.php';

/**
 * Stores survey results. The supervisor starts it as its own PHP process and
 * checks it over Beanstalk (CHECK_BY_BEANSTALK).
 *
 * - Each record the AGI script publishes is validated again (Handoff) and
 *   written by FeedbackStore.
 * - Every 30 seconds, finished sessions get their agent, queue and talk time
 *   from the CDR (Attribution).
 * - Requests from the module's page (a test call, a follow-up call-back,
 *   deleting a number's cap key) arrive on CallFeedbackConf::COMMANDS_TUBE: the web process has no
 *   AMI connection, this worker does.
 * - Once at start and then every night: callers' numbers past the PBX's
 *   retention period are cleared, and expired frequency-cap keys deleted.
 * - The daily or weekly summary email, if one is set.
 *
 * The logic lives in plain, unit-tested classes; this class only wires them
 * to the PBX.
 */
class WorkerCallFeedback extends WorkerBase
{
    /** The context phones dial from; outgoing calls placed there follow the outgoing routes. */
    private const string PHONES_CONTEXT = 'all_peers';

    /** Caller ID name the phone shows for a test call. */
    private const string TEST_CALLER_NAME = 'Survey test';

    private const int ATTRIBUTION_EVERY_SECONDS = 30;

    /** Nightly maintenance runs on the first loop after this hour (PBX time). */
    private const int NIGHTLY_HOUR = 3;

    /** The summary email goes out on the first loop after this hour (PBX time). */
    private const int SUMMARY_HOUR = 8;

    /** Internal bookkeeping in m_Settings: the day the last summary went out. */
    private const string SUMMARY_SENT_ON = 'summary_sent_on';

    private FeedbackStore $store;

    private SurveyRepository $surveys;

    private FollowUps $followUps;

    private \PDO $moduleDb;

    public function start(array $argv): void
    {
        $config = $this->di->getShared('config');
        $cdrFile = (string)$config->path('cdrDatabase.dbfile');
        $configFile = (string)$config->path('database.dbfile');
        $moduleFile = PbxExtensionUtils::getModuleDir(CallFeedbackConf::MODULE_ID) . '/db/module.db';

        $this->store = new FeedbackStore($moduleFile, StorageInfo::isWalSafeFilesystem($moduleFile));
        $this->surveys = new SurveyRepository(CdrReader::openReadOnly($moduleFile));
        $this->moduleDb = new \PDO('sqlite:' . $moduleFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->moduleDb->exec('PRAGMA busy_timeout = 5000');
        $this->followUps = new FollowUps($this->moduleDb);
        $cdr = new CdrReader($cdrFile);

        $client = new BeanstalkClient(self::class);
        $client->subscribe(self::class, [$this, 'onRecord']);
        $client->subscribe(CallFeedbackConf::COMMANDS_TUBE, [$this, 'onCommand']);
        $client->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);

        $lastAttribution = 0;
        $lastMaintenance = '';
        $lastSummaryCheck = 0;
        while ($this->needRestart === false) {
            $client->wait(1);
            if (time() - $lastAttribution >= self::ATTRIBUTION_EVERY_SECONDS) {
                $lastAttribution = time();
                $this->attribute($cdr, $configFile);
            }
            // At start, then once a day after NIGHTLY_HOUR.
            $today = date('Y-m-d');
            if ($lastMaintenance === '' || ($lastMaintenance !== $today && (int)date('G') >= self::NIGHTLY_HOUR)) {
                $lastMaintenance = $today;
                $this->maintenance($configFile);
            }
            if ((int)date('G') >= self::SUMMARY_HOUR && time() - $lastSummaryCheck >= 60) {
                $lastSummaryCheck = time();
                $this->summary();
            }
        }
    }

    /** One end-of-call record from the AGI script. */
    public function onRecord(BeanstalkClient $message): void
    {
        try {
            $record = Handoff::decode($message->getBody());
            $result = $this->store->record($record, $this->surveys->find($record['survey']));
            if ($result === FeedbackStore::UNKNOWN_SURVEY) {
                SystemMessages::sysLogMsg(static::class, "Record for unknown survey {$record['survey']} dropped", LOG_WARNING);
            }
        } catch (InvalidArgumentException $e) {
            SystemMessages::sysLogMsg(static::class, 'Survey record rejected: ' . $e->getMessage(), LOG_WARNING);
        } catch (Throwable $e) {
            // Logged, not rethrown: a rethrow would bury the job and stop nothing.
            SystemMessages::sysLogMsg(static::class, 'Storing a survey record failed: ' . $e->getMessage(), LOG_ERR);
        }
    }

    /**
     * Opens a follow-up for a detractor and, if recipients are set, emails them.
     * Runs once the session's call is confirmed in the CDR. A failed email is
     * logged; the case is listed either way.
     *
     * @param array{id: int, survey_id: int, agent: ?string} $counted
     */
    private function followUp(array $counted): void
    {
        $survey = $this->surveys->find($counted['survey_id']);
        if ($survey === null) {
            return;
        }
        $session = $this->store->sessionById($counted['id']);
        $answers = $this->store->answersOf($counted['id']);
        $settings = ModuleSettings::load($this->moduleDb);
        if ($session === null || !$this->followUps->openIfDetractor($counted['id'], $survey, $answers, $settings, time())
            || $settings['alert_emails'] === []) {
            return;
        }
        $message = AlertMessage::build(
            (string)PbxSettings::getValueByKey(PbxSettings::PBX_LANGUAGE),
            (string)$survey['name'],
            (string)$survey['questions'][1]['scale'],
            $answers[1],
            $counted['agent'],
            (int)$session['ended_at'],
            LanInterfaces::buildAdminUrl(AlertMessage::PAGE_PATH),
            $counted['agent'] === null ? null : Extensions::findFirstByNumber($counted['agent'])?->callerid,
        );
        $error = null;
        if ((new Notifications())->sendMail($settings['alert_emails'], $message['subject'], $message['body'], '', $error, false)) {
            $this->followUps->markAlerted($counted['id'], time());
        } else {
            SystemMessages::sysLogMsg(static::class, 'Detractor alert not sent: ' . $error, LOG_WARNING);
        }
    }

    /**
     * A request from the page. The page has validated it; it is checked again
     * here, because anything that can publish to the queue reaches this.
     */
    public function onCommand(BeanstalkClient $message): void
    {
        try {
            $request = json_decode($message->getBody(), true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($request)) {
                throw new InvalidArgumentException('Unknown command');
            }
            match ($request['command'] ?? '') {
                CallFeedbackConf::COMMAND_TEST_CALL => $this->testCall((string)($request['survey'] ?? ''), (string)($request['extension'] ?? '')),
                CallFeedbackConf::COMMAND_FORGET_NUMBER => $this->deleteCapKey((string)($request['digits'] ?? '')),
                CallFeedbackConf::COMMAND_CALL_BACK => $this->callBack((string)($request['callback'] ?? '')),
                default => throw new InvalidArgumentException('Unknown command'),
            };
        } catch (InvalidArgumentException | \JsonException $e) {
            SystemMessages::sysLogMsg(static::class, 'Command rejected: ' . $e->getMessage(), LOG_WARNING);
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Command failed: ' . $e->getMessage(), LOG_ERR);
        }
    }

    /**
     * Rings the extension; once answered, the call enters the test context with
     * the survey ID as its extension and plays that version without the rules.
     */
    private function testCall(string $surveyId, string $extension): void
    {
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $surveyId) || !preg_match('/^[0-9]{2,8}$/', $extension)) {
            throw new InvalidArgumentException('Invalid test call request');
        }
        $survey = $this->surveys->find((int)$surveyId);
        if ($survey === null || $survey['status'] === SurveyRepository::ARCHIVED) {
            throw new InvalidArgumentException('No such survey to test');
        }
        $config = $this->di->getShared('config');
        $known = array_column(ConfigOptions::extensions(CdrReader::openReadOnly((string)$config->path('database.dbfile'))), 'number');
        if (!in_array($extension, $known, true)) {
            throw new InvalidArgumentException('Unknown extension');
        }
        $result = Util::getAstManager('off')->Originate(
            "Local/$extension@internal",
            $surveyId,
            DialplanBuilder::CTX_TEST,
            1,
            null,
            null,
            30000,
            '"' . self::TEST_CALLER_NAME . '" <' . $surveyId . '>',
            "CF_TEST_EXT=$extension",
        );
        if (strcasecmp((string)($result['Response'] ?? ''), 'Success') !== 0) {
            throw new \RuntimeException('Originate refused: ' . ($result['Message'] ?? 'no response'));
        }
    }

    /**
     * Places a logged call-back: rings the extension and, once it answers,
     * dials the number through the PBX's outgoing routes, as the core's own
     * click-to-call does, without surveying the customer again. The number and extension are read from the log
     * the page wrote, never taken from the queue message.
     */
    private function callBack(string $id): void
    {
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $id)) {
            throw new InvalidArgumentException('Invalid call-back request');
        }
        $entry = (new CallBacks($this->moduleDb))->find((int)$id);
        if ($entry === null || !preg_match('/^[0-9]{1,20}$/', $entry['number'])) {
            throw new InvalidArgumentException('No such call-back');
        }
        $config = $this->di->getShared('config');
        $known = array_column(ConfigOptions::extensions(CdrReader::openReadOnly((string)$config->path('database.dbfile'))), 'number');
        if (!in_array($entry['extension'], $known, true)) {
            throw new InvalidArgumentException('Unknown extension');
        }
        // As the core's Util::amiOriginate() (click-to-call), plus CF_SKIP so the
        // customer is not surveyed again at the end of the call-back.
        $result = Util::getAstManager('off')->Originate(
            'Local/' . $entry['extension'] . '@internal-originate',
            $entry['number'],
            self::PHONES_CONTEXT,
            1,
            null,
            null,
            null,
            null,
            'pt1c_cid=' . $entry['number'] . ',__peer_mobile=,__' . DialplanBuilder::SKIP_VARIABLE . '=1',
            null,
            true,
        );
        if (strcasecmp((string)($result['Response'] ?? ''), 'Success') !== 0) {
            throw new \RuntimeException('Originate refused: ' . ($result['Message'] ?? 'no response'));
        }
    }

    /**
     * The daily (yesterday) or weekly (the last 7 days, sent on Mondays)
     * summary email, once per day at most.
     */
    private function summary(): void
    {
        try {
            $settings = ModuleSettings::load($this->moduleDb);
            $today = date('Y-m-d');
            $weekly = $settings['summary'] === 'weekly';
            $sentOn = (string)$this->moduleDb->query("SELECT value FROM m_Settings WHERE name = '" . self::SUMMARY_SENT_ON . "'")->fetchColumn();
            if ($settings['summary'] === 'off' || $settings['alert_emails'] === [] || $sentOn === $today
                || ($weekly && date('N') !== '1')) {
                return;
            }
            $to = date('Y-m-d', strtotime('yesterday'));
            $from = $weekly ? date('Y-m-d', strtotime('-7 days')) : $to;
            $results = new Results($this->moduleDb, (int)$settings['min_sample']);
            $overviews = [];
            foreach ($results->versions() as $version) {
                if ($version['status'] === SurveyRepository::ACTIVE) {
                    $overviews[] = $results->overview(['survey' => (string)$version['id'], 'from' => $from, 'to' => $to, 'direction' => '']);
                }
            }
            $open = (new FollowUps($this->moduleDb))->list('open', 1)['total'];
            $message = SummaryMessage::build((string)PbxSettings::getValueByKey(PbxSettings::PBX_LANGUAGE), $weekly, $from, $to, $overviews, $open,
                LanInterfaces::buildAdminUrl(AlertMessage::OVERVIEW_PATH));
            // Recorded first, so a failing mail server is not retried every minute.
            $this->moduleDb->prepare('INSERT INTO m_Settings (name, value) VALUES (?, ?)
                ON CONFLICT (name) DO UPDATE SET value = excluded.value')->execute([self::SUMMARY_SENT_ON, $today]);
            $error = null;
            if (!(new Notifications())->sendMail($settings['alert_emails'], $message['subject'], $message['body'], '', $error, false)) {
                SystemMessages::sysLogMsg(static::class, 'Summary email not sent: ' . $error, LOG_WARNING);
            }
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Summary failed: ' . $e->getMessage(), LOG_ERR);
        }
    }

    /** Deletes one number's frequency-cap key. */
    private function deleteCapKey(string $digits): void
    {
        if (!preg_match('/^[0-9]{1,20}$/', $digits)) {
            throw new InvalidArgumentException('Invalid number');
        }
        Util::getAstManager('off')->sendRequestTimeout('DBDel', ['Family' => DialplanBuilder::DB_CAP, 'Key' => $digits]);
    }

    /**
     * Retention: clears callers' numbers past the PBX's retention period, and
     * deletes frequency-cap keys older than their lifetime. A key is deleted
     * only if its stored time is still past the cut-off: the number may have
     * been asked again since. Logs counts, never numbers.
     */
    private function maintenance(string $configFile): void
    {
        try {
            $retention = (int)CdrReader::pbxSetting($configFile, 'PBXRecordSavePeriod', CallFeedbackConf::DEFAULT_RETENTION_DAYS);
            $privacy = new Privacy($this->moduleDb);
            $lifetime = $privacy->capKeyLifetimeDays($retention);
            $deleted = 0;
            if ($lifetime > 0) {
                $check = $privacy->keysToCheck($lifetime, time());
                $ami = Util::getAstManager('off');
                foreach ($check['numbers'] as $digits) {
                    $stored = $ami->DBGet(DialplanBuilder::DB_CAP, $digits);
                    $value = (string)($stored['data']['DBGetResponse'][0]['Val'] ?? '');
                    if ($value !== '' && ctype_digit($value) && (int)$value < $check['cutoff']) {
                        $ami->sendRequestTimeout('DBDel', ['Family' => DialplanBuilder::DB_CAP, 'Key' => $digits]);
                        $deleted++;
                    }
                }
                $privacy->markKeysChecked($check['cutoff']);
            }
            $cleared = $privacy->applyRetention($retention, time());
            if ($deleted > 0 || $cleared > 0) {
                SystemMessages::sysLogMsg(static::class, "Retention: cleared $cleared caller number(s), deleted $deleted frequency-cap key(s)", LOG_INFO);
            }
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Maintenance failed: ' . $e->getMessage(), LOG_ERR);
        }
    }

    private function attribute(CdrReader $cdr, string $configFile): void
    {
        try {
            // Read every time: providers can be added while the worker runs.
            $indexer = new CallIndexer(CdrReader::providerIds($configFile));
            $result = (new Attribution($this->store, $cdr, $indexer, $this->surveys))->run(time());
            foreach ($result['counted'] as $counted) {
                $this->followUp($counted);
            }
            if ($result['discarded'] > 0) {
                SystemMessages::sysLogMsg(
                    static::class,
                    "Discarded {$result['discarded']} survey session(s) whose call never appeared in the CDR",
                    LOG_WARNING
                );
            }
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Attribution failed: ' . $e->getMessage(), LOG_ERR);
        }
    }
}

WorkerCallFeedback::startWorker($argv ?? []);
