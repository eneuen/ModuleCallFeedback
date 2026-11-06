<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One answer: the key a caller pressed for one question of a survey session.
 *
 * The core creates the table from these annotations; FeedbackStore writes it.
 *
 * @Indexes(
 *     [name='unique_answer', columns=['session_id', 'position'], type='UNIQUE']
 * )
 */
class SurveyAnswer extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $session_id;

    /**
     * Question number in the survey, from 1.
     *
     * @Column(type="integer", nullable=false)
     */
    public $position;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $value;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $answered_at;

    public function initialize(): void
    {
        $this->setSource('m_SurveyAnswers');
        parent::initialize();
    }
}
