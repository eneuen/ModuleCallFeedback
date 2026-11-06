<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * A route a survey family runs on. Only one active survey may target a route.
 *
 * The core creates the table from these annotations.
 *
 * @Indexes(
 *     [name='unique_target', columns=['family_id', 'direction', 'route_id'], type='UNIQUE']
 * )
 */
class SurveyTarget extends ModulesModelsBase
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
    public $family_id;

    /**
     * 'in' (m_IncomingRoutingTable.id) or 'out' (m_OutgoingRoutingTable.id).
     *
     * @Column(type="string", nullable=false)
     */
    public $direction;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $route_id;

    public function initialize(): void
    {
        $this->setSource('m_SurveyTargets');
        parent::initialize();
    }
}
