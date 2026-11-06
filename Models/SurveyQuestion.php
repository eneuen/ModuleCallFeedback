<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One question of a survey version. The scale is a fixed preset
 * (ScalePresets), so the keys and the stored values are always known.
 *
 * The core creates the table from these annotations.
 *
 * @Indexes(
 *     [name='unique_position', columns=['survey_id', 'position'], type='UNIQUE']
 * )
 */
class SurveyQuestion extends ModulesModelsBase
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
    public $survey_id;

    /**
     * 1-3; question 1 is the survey's headline score.
     *
     * @Column(type="integer", nullable=false)
     */
    public $position;

    /**
     * 'csat5', 'nps11', 'ces5' or 'yesno'.
     *
     * @Column(type="string", nullable=false)
     */
    public $scale;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $prompt_id;

    /**
     * @Column(type="integer", nullable=false, default="6")
     */
    public $timeout_seconds;

    /**
     * @Column(type="integer", nullable=false, default="2")
     */
    public $retries;

    public function initialize(): void
    {
        $this->setSource('m_SurveyQuestions');
        parent::initialize();
    }
}
