<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One row per call the survey was offered on: where it came from, how far
 * the caller got, and (once the call is in the CDR) who answered and for how
 * long. Answers are in SurveyAnswer.
 *
 * The core creates the table from these annotations; FeedbackStore writes it.
 *
 * @Indexes(
 *     [name='unique_call', columns=['linkedid', 'survey_id'], type='UNIQUE'],
 *     [name='ended_at', columns=['ended_at'], type=''],
 *     [name='attributed_at', columns=['attributed_at'], type='']
 * )
 */
class SurveySession extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="string", nullable=false)
     */
    public $linkedid;

    /**
     * Survey version; 0 is the built-in test survey.
     *
     * @Column(type="integer", nullable=false, default="0")
     */
    public $survey_id;

    /**
     * 'in' or 'out'.
     *
     * @Column(type="string", nullable=false)
     */
    public $direction;

    /**
     * 'offered' (no valid key), 'started' (some answers) or 'completed'.
     *
     * @Column(type="string", nullable=false)
     */
    public $status;

    /**
     * Inbound: the incoming context (provider ID) the call came through.
     *
     * @Column(type="string", nullable=true)
     */
    public $context_id;

    /**
     * Outbound: the outgoing route's ID.
     *
     * @Column(type="integer", nullable=true)
     */
    public $route_id;

    /**
     * @Column(type="string", nullable=true)
     */
    public $did;

    /**
     * The customer's number, digits and + only. Empty when withheld.
     *
     * @Column(type="string", nullable=true)
     */
    public $caller_num;

    /**
     * Unix time the agent answered, as measured in the dialplan.
     *
     * @Column(type="integer", nullable=true)
     */
    public $answer_at;

    /**
     * Talk time measured in the dialplan when the survey started.
     *
     * @Column(type="integer", nullable=true)
     */
    public $talk_seconds;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $offered_at;

    /**
     * Unix time the customer's channel ended.
     *
     * @Column(type="integer", nullable=false)
     */
    public $ended_at;

    /**
     * From the CDR: inbound, the first extension that talked to the caller; outbound, the extension that called.
     *
     * @Column(type="string", nullable=true)
     */
    public $agent;

    /**
     * From the CDR: the first queue the call went through.
     *
     * @Column(type="string", nullable=true)
     */
    public $queue;

    /**
     * From the CDR: the call's talk time.
     *
     * @Column(type="integer", nullable=true)
     */
    public $cdr_talk_seconds;

    /**
     * 1 when the CDR talk time is under the survey's minimum: kept, but left out of scores.
     *
     * @Column(type="integer", nullable=false, default="0")
     */
    public $excluded;

    /**
     * When the CDR fields were filled in; empty while the call is pending.
     *
     * @Column(type="integer", nullable=true)
     */
    public $attributed_at;

    public function initialize(): void
    {
        $this->setSource('m_SurveySessions');
        parent::initialize();
    }
}
