<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One call-back placed from a follow-up: the number, the extension that was
 * rung first, who asked and when. Kept as an audit log.
 *
 * The core creates the table from these annotations.
 *
 * @Indexes(
 *     [name='followup', columns=['followup_id'], type='']
 * )
 */
class CallBack extends ModulesModelsBase
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
    public $followup_id;

    /**
     * The number dialled, digits only.
     *
     * @Column(type="string", nullable=false)
     */
    public $number;

    /**
     * The extension rung first; the call goes out once it answers.
     *
     * @Column(type="string", nullable=false)
     */
    public $extension;

    /**
     * Login of the admin who placed it.
     *
     * @Column(type="string", nullable=true)
     */
    public $requested_by;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $requested_at;

    /**
     * Whether the number had to be typed in ('typed') or was one click ('listed').
     *
     * @Column(type="string", nullable=false)
     */
    public $confirmation;

    public function initialize(): void
    {
        $this->setSource('m_CallBacks');
        parent::initialize();
    }
}
