<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Module settings as name/value pairs (ModuleSettings knows the names and defaults).
 *
 * The core creates the table from these annotations.
 *
 * @Indexes(
 *     [name='unique_name', columns=['name'], type='UNIQUE']
 * )
 */
class Setting extends ModulesModelsBase
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
    public $name;

    /**
     * @Column(type="string", nullable=true)
     */
    public $value;

    public function initialize(): void
    {
        $this->setSource('m_Settings');
        parent::initialize();
    }
}
