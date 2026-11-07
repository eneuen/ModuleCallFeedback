<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use MikoPBX\Common\Models\SoundFiles;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Core\Workers\Libs\WorkerModelsEvents\Actions\ReloadDialplanAction;
use MikoPBX\Core\Workers\WorkerModelsEvents;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\Modules\PbxExtensionUtils;
use Throwable;

/**
 * Module hooks. The dialplan itself is built by DialplanBuilder from the
 * stored surveys.
 *
 * The configuration pages write through SurveyEditor (plain PDO, so no
 * model events) and request a dialplan reload themselves after each change.
 *
 * Each dialplan hook builds the plan afresh from the databases (a few small
 * reads), because this class lives on in long-running core workers and must
 * never serve a cached, stale survey. If the module database can't be read,
 * the hooks add nothing, so calls carry on without a survey.
 */
class CallFeedbackConf extends ConfigClass
{
    public const string MODULE_ID = 'ModuleCallFeedback';

    /**
     * Requests from the module's page to its worker, such as a test call. Kept
     * here, not on the worker class, so the page never loads the worker's file.
     */
    public const string COMMANDS_TUBE = 'ModuleCallFeedback-commands';
    public const string COMMAND_TEST_CALL = 'test_call';
    public const string COMMAND_FORGET_NUMBER = 'forget_number';
    public const string COMMAND_CALL_BACK = 'call_back';

    public function extensionGenContexts(): string
    {
        return $this->builder()?->contexts() ?? '';
    }

    public function generateIncomingRoutAfterDialContext(string $uniqId): string
    {
        return $this->builder()?->incomingAfterDial() ?? '';
    }

    public function extensionGenInternalUsersPreDial(): string
    {
        return $this->builder()?->internalUsersPreDial() ?? '';
    }

    public function generateOutRoutContext(array $rout): string
    {
        return $this->builder()?->outboundBeforeDial($rout) ?? '';
    }

    /** The worker that stores survey results. */
    public function getModuleWorkers(): array
    {
        return [
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker' => WorkerCallFeedback::class,
            ],
        ];
    }

    /** Enabling a module doesn't reload the dialplan by itself. */
    public function onAfterModuleEnable(): void
    {
        WorkerModelsEvents::invokeAction(ReloadDialplanAction::class);
    }

    /** Removes the module's dialplan once its hooks are no longer called. */
    public function onAfterModuleDisable(): void
    {
        WorkerModelsEvents::invokeAction(ReloadDialplanAction::class);
    }

    private function builder(): ?DialplanBuilder
    {
        try {
            $config = $this->di->getShared('config');
            $moduleFile = PbxExtensionUtils::getModuleDir(self::MODULE_ID) . '/db/module.db';

            return DialplanBuilder::fromDatabases(
                CdrReader::openReadOnly($moduleFile),
                CdrReader::openReadOnly((string)$config->path('database.dbfile')),
                [self::class, 'promptPath'],
            );
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Survey dialplan not generated: ' . $e->getMessage(), LOG_ERR);

            return null;
        }
    }

    /** A sound file ID as a Playback path (without extension), or null when it is gone. */
    public static function promptPath(int $soundFileId): ?string
    {
        $file = SoundFiles::findFirstById($soundFileId);
        if ($file === null || (string)$file->path === '') {
            return null;
        }
        $path = SoundFiles::resolveAsteriskAudioPath((string)$file->path);

        return $path === '' ? null : $path;
    }
}
