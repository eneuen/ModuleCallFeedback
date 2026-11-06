<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Setup;

use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use Modules\ModuleCallFeedback\Lib\DialplanBuilder;
use Throwable;

/**
 * Installer. The base class creates the tables from the model annotations,
 * links agi-bin/ into Asterisk's AGI folder and adds the sidebar entry.
 */
class PbxExtensionSetup extends PbxExtensionSetupBase
{
    /**
     * Also removes what lives outside the module folder: the AGI link (the
     * core creates it on install but never removes it) and the module's astdb
     * family.
     */
    public function unInstallFiles(bool $keepSettings = false): bool
    {
        $link = Directories::getDir(Directories::AST_AGI_BIN_DIR) . '/' . DialplanBuilder::AGI_SCRIPT;
        if (is_link($link) && str_starts_with((string)readlink($link), $this->moduleDir . '/')) {
            unlink($link);
        }
        try {
            Util::getAstManager('off')->sendRequestTimeout('DBDelTree', ['Family' => DialplanBuilder::DB_TALK]);
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Could not clear the astdb family: ' . $e->getMessage(), LOG_WARNING);
        }

        return parent::unInstallFiles($keepSettings);
    }
}
