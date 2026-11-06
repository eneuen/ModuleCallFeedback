<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;

/**
 * Fills in each survey session's agent, queue and talk time from the CDR once
 * the call has finished.
 *
 * - The dialplan only knows a hint; the CDR legs of the call (by linkedid)
 *   say who really answered. CallIndexer turns them into one row.
 * - A session whose CDR talk time is under the survey's minimum is marked
 *   excluded: kept, but left out of scores.
 * - A session whose call never shows up in the CDR is discarded: the worker
 *   accepts results only for calls that really happened.
 *
 * Plain PHP over CdrReader and FeedbackStore, so it is tested without a PBX.
 */
final class Attribution
{
    /** Wait this long after a call ends before reading its CDR legs. */
    public const int SETTLE_SECONDS = 15;

    /** A session whose call has no CDR legs this long after it ended is discarded. */
    public const int GIVE_UP_SECONDS = 600;

    private const int BATCH = 100;

    public function __construct(
        private readonly FeedbackStore $store,
        private readonly CdrReader $cdr,
        private readonly CallIndexer $indexer,
    ) {
    }

    /**
     * @return array{attributed: int, discarded: int, waiting: int}
     */
    public function run(int $now): array
    {
        $result = ['attributed' => 0, 'discarded' => 0, 'waiting' => 0];
        foreach ($this->store->pendingAttribution($now - self::SETTLE_SECONDS, self::BATCH) as $session) {
            $legs = $this->cdr->legsOfCall((string)$session['linkedid']);
            if ($legs === []) {
                if ((int)$session['ended_at'] <= $now - self::GIVE_UP_SECONDS) {
                    $this->store->discard((int)$session['id']);
                    $result['discarded']++;
                } else {
                    $result['waiting']++;
                }
                continue;
            }

            try {
                $call = $this->indexer->index($legs);
            } catch (InvalidArgumentException) {
                // Legs CallIndexer can't read: keep the session, without CDR fields,
                // so it doesn't hold up the sessions behind it.
                $this->store->attribute((int)$session['id'], null, null, null, false, $now);
                $result['attributed']++;
                continue;
            }
            $survey = SurveyCatalog::find((int)$session['survey_id']);
            $talk = (int)$call['talk_seconds'];
            $agent = $session['direction'] === 'out' ? $call['caller_num'] : $call['answered_by'];
            $this->store->attribute(
                (int)$session['id'],
                $agent === null || $agent === '' ? null : (string)$agent,
                $call['first_queue'] === null ? null : (string)$call['first_queue'],
                $talk,
                $survey !== null && $talk < $survey['min_talk_seconds'],
                $now
            );
            $result['attributed']++;
        }

        return $result;
    }
}
