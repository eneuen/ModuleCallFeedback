<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * How many eligible calls a rule left unasked, per day, direction, route and
 * reason (for example too short). Unanswered calls are not counted here; the
 * CDR has them.
 *
 * The core creates the table from these annotations; FeedbackStore writes it.
 *
 * @Indexes(
 *     [name='unique_bucket', columns=['day', 'direction', 'route_key', 'reason'], type='UNIQUE']
 * )
 */
class SkipCount extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * 'YYYY-MM-DD', PBX local time.
     *
     * @Column(type="string", nullable=false)
     */
    public $day;

    /**
     * @Column(type="string", nullable=false)
     */
    public $direction;

    /**
     * Inbound 'in:<context>:<did>', outbound 'out:<route id>'.
     *
     * @Column(type="string", nullable=false)
     */
    public $route_key;

    /**
     * @Column(type="string", nullable=false)
     */
    public $reason;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $count;

    public function initialize(): void
    {
        $this->setSource('m_SkipCounts');
        parent::initialize();
    }
}
