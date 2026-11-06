<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;
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
 * - Requests from the module's page (a test call) arrive on CallFeedbackConf::COMMANDS_TUBE:
 *   the web process has no AMI connection, this worker does.
 *
 * The logic lives in plain, unit-tested classes; this class only wires them
 * to the PBX.
 */
class WorkerCallFeedback extends WorkerBase
{
    /** Caller ID name the phone shows for a test call. */
    private const string TEST_CALLER_NAME = 'Survey test';

    private const int ATTRIBUTION_EVERY_SECONDS = 30;

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
        while ($this->needRestart === false) {
            $client->wait(1);
            if (time() - $lastAttribution >= self::ATTRIBUTION_EVERY_SECONDS) {
                $lastAttribution = time();
                $this->attribute($cdr, $configFile);
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
            if (!is_array($request) || ($request['command'] ?? '') !== CallFeedbackConf::COMMAND_TEST_CALL) {
                throw new InvalidArgumentException('Unknown command');
            }
            $this->testCall((string)($request['survey'] ?? ''), (string)($request['extension'] ?? ''));
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
