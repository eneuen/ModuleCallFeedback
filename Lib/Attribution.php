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
 *   accepts results only for calls that really happened. Follow-ups and
 *   alerts are opened only after this, for sessions that count.
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
        private readonly SurveyRepository $surveys,
    ) {
    }

    /**
     * @return array{attributed: int, discarded: int, waiting: int, counted: list<array{id: int, survey_id: int, agent: ?string}>}
     *         counted: the sessions attributed in this run that count towards
     *         results (not excluded), for follow-ups.
     */
    public function run(int $now): array
    {
        $result = ['attributed' => 0, 'discarded' => 0, 'waiting' => 0, 'counted' => []];
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
                $result['counted'][] = ['id' => (int)$session['id'], 'survey_id' => (int)$session['survey_id'], 'agent' => null];
                continue;
            }
            $survey = $this->surveys->find((int)$session['survey_id']);
            $talk = (int)$call['talk_seconds'];
            $agent = $session['direction'] === 'out' ? $call['caller_num'] : $call['answered_by'];
            $agent = $agent === null || $agent === '' ? null : (string)$agent;
            $excluded = $survey !== null && $talk < $survey['min_talk_seconds'];
            $this->store->attribute(
                (int)$session['id'],
                $agent,
                $call['first_queue'] === null ? null : (string)$call['first_queue'],
                $talk,
                $excluded,
                $now
            );
            $result['attributed']++;
            if (!$excluded) {
                $result['counted'][] = ['id' => (int)$session['id'], 'survey_id' => (int)$session['survey_id'], 'agent' => $agent];
            }
        }

        return $result;
    }
}
