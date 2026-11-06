<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\SystemMessages;
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
 *
 * The logic lives in plain, unit-tested classes; this class only wires them
 * to the PBX.
 */
class WorkerCallFeedback extends WorkerBase
{
    public const string MODULE_ID = 'ModuleCallFeedback';

    private const int ATTRIBUTION_EVERY_SECONDS = 30;

    private FeedbackStore $store;

    public function start(array $argv): void
    {
        $config = $this->di->getShared('config');
        $cdrFile = (string)$config->path('cdrDatabase.dbfile');
        $configFile = (string)$config->path('database.dbfile');
        $moduleFile = PbxExtensionUtils::getModuleDir(self::MODULE_ID) . '/db/module.db';

        $this->store = new FeedbackStore($moduleFile, StorageInfo::isWalSafeFilesystem($moduleFile));
        $cdr = new CdrReader($cdrFile);

        $client = new BeanstalkClient(self::class);
        $client->subscribe(self::class, [$this, 'onRecord']);
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
            $result = $this->store->record($record, SurveyCatalog::find($record['survey']));
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

    private function attribute(CdrReader $cdr, string $configFile): void
    {
        try {
            // Read every time: providers can be added while the worker runs.
            $indexer = new CallIndexer(CdrReader::providerIds($configFile));
            $result = (new Attribution($this->store, $cdr, $indexer))->run(time());
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
