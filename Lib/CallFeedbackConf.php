<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use MikoPBX\Core\Workers\Libs\WorkerModelsEvents\Actions\ReloadDialplanAction;
use MikoPBX\Core\Workers\WorkerModelsEvents;
use MikoPBX\Modules\Config\ConfigClass;

/**
 * Module hooks. The dialplan itself is built by DialplanBuilder.
 */
class CallFeedbackConf extends ConfigClass
{
    public function extensionGenContexts(): string
    {
        return DialplanBuilder::contexts();
    }

    public function generateIncomingRoutBeforeDial(string $rout_number): string
    {
        return DialplanBuilder::incomingBeforeDial($rout_number);
    }

    public function generateIncomingRoutAfterDialContext(string $uniqId): string
    {
        return DialplanBuilder::incomingAfterDial($uniqId);
    }

    public function extensionGenInternalUsersPreDial(): string
    {
        return DialplanBuilder::internalUsersPreDial();
    }

    public function generateOutRoutContext(array $rout): string
    {
        return DialplanBuilder::outboundBeforeDial($rout);
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
}
