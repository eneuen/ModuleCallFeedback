<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One survey version. Editing an active survey creates a new draft version in
 * the same family, so live wording never changes under existing results.
 * Questions and prompts are fixed once a version is active; its rules (minimum
 * talk time, sampling, frequency cap) and its family's targets can change.
 *
 * The name is for admins only and never reaches the dialplan.
 *
 * The core creates the table from these annotations.
 *
 * @Indexes(
 *     [name='family', columns=['family_id', 'version'], type='UNIQUE'],
 *     [name='status', columns=['status'], type='']
 * )
 */
class Survey extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * ID of the family's first version; every version of one survey shares it.
     *
     * @Column(type="integer", nullable=false)
     */
    public $family_id;

    /**
     * @Column(type="integer", nullable=false, default="1")
     */
    public $version;

    /**
     * @Column(type="string", nullable=false)
     */
    public $name;

    /**
     * 'draft', 'active' or 'archived'.
     *
     * @Column(type="string", nullable=false, default="draft")
     */
    public $status;

    /**
     * Prompt language, set on the channel during the survey so built-in
     * sounds match: 'en-en' or 'ru-ru' (MikoPBX's sound folder names).
     *
     * @Column(type="string", nullable=false, default="en-en")
     */
    public $language;

    /**
     * Sound file IDs (m_SoundFiles.id), never paths.
     *
     * @Column(type="integer", nullable=true)
     */
    public $intro_prompt_id;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $invalid_prompt_id;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $thanks_prompt_id;

    /**
     * @Column(type="integer", nullable=false, default="20")
     */
    public $min_talk_seconds;

    /**
     * @Column(type="integer", nullable=false, default="100")
     */
    public $sample_percent;

    /**
     * Don't survey the same number again within this many days; 0 = no cap.
     *
     * @Column(type="integer", nullable=false, default="30")
     */
    public $frequency_cap_days;

    /**
     * Days of the week the survey runs, as a bit mask: Monday 1, Tuesday 2 ...
     * Sunday 64; 127 is every day.
     *
     * @Column(type="integer", nullable=false, default="127")
     */
    public $hours_days;

    /**
     * 'HH:MM' the survey starts each day, PBX time; empty with hours_end for all day.
     *
     * @Column(type="string", nullable=true)
     */
    public $hours_start;

    /**
     * 'HH:MM' the survey stops each day; earlier than hours_start runs past midnight.
     *
     * @Column(type="string", nullable=true)
     */
    public $hours_end;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $created_at;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $activated_at;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $archived_at;

    public function initialize(): void
    {
        $this->setSource('m_Surveys');
        parent::initialize();
    }
}
