<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * A number that is never surveyed (digits only).
 *
 * The core creates the table from these annotations.
 *
 * @Indexes(
 *     [name='unique_number', columns=['number'], type='UNIQUE']
 * )
 */
class Exclusion extends ModulesModelsBase
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
    public $number;

    /**
     * @Column(type="string", nullable=true)
     */
    public $note;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $created_at;

    public function initialize(): void
    {
        $this->setSource('m_Exclusions');
        parent::initialize();
    }
}
