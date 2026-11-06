<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * A detractor case: a session whose headline score is at or below the
 * detractor threshold, to be called back.
 *
 * The core creates the table from these annotations.
 *
 * @Indexes(
 *     [name='unique_session', columns=['session_id'], type='UNIQUE'],
 *     [name='status', columns=['status'], type='']
 * )
 */
class FollowUp extends ModulesModelsBase
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
     * 'open', 'contacted' or 'resolved'.
     *
     * @Column(type="string", nullable=false, default="open")
     */
    public $status;

    /**
     * @Column(type="string", nullable=true)
     */
    public $note;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $created_at;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $updated_at;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $closed_at;

    /**
     * Login of the admin who resolved it.
     *
     * @Column(type="string", nullable=true)
     */
    public $closed_by;

    /**
     * When the detractor email was sent.
     *
     * @Column(type="integer", nullable=true)
     */
    public $alerted_at;

    public function initialize(): void
    {
        $this->setSource('m_FollowUps');
        parent::initialize();
    }
}
