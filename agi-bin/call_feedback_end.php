#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * End-of-call hand-off. Run by the survey's hangup handler, once per surveyed
 * (or rule-skipped) call, after the customer has gone, so nobody waits on it.
 *
 * It reads the survey's CF_* channel variables, validates every value against
 * a fixed pattern (Handoff) and publishes the record to the module worker's
 * Beanstalk queue. Nothing else: no shell, no files, no network beyond the
 * local queue. Invalid records are logged and dropped.
 */

declare(strict_types=1);

use MikoPBX\Core\Asterisk\AGI;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleCallFeedback\Lib\Handoff;
use Modules\ModuleCallFeedback\Lib\WorkerCallFeedback;

require_once 'Globals.php';

set_time_limit(5);

try {
    $agi = new AGI();
    $vars = [];
    foreach (Handoff::channelVariables() as $name) {
        $vars[$name] = (string)$agi->get_variable($name, true);
    }
    $record = Handoff::fromChannel((string)$agi->get_variable('CHANNEL(linkedid)', true), $vars, time());
    (new BeanstalkClient(WorkerCallFeedback::class))->publish(Handoff::encode($record));
} catch (InvalidArgumentException $e) {
    SystemMessages::sysLogMsg('CallFeedback', 'Survey record rejected: ' . $e->getMessage(), LOG_WARNING);
} catch (Throwable $e) {
    SystemMessages::sysLogMsg('CallFeedback', 'Survey hand-off failed: ' . $e->getMessage(), LOG_ERR);
}
