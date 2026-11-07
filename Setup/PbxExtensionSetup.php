<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Setup;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use Modules\ModuleCallFeedback\Lib\CdrReader;
use Modules\ModuleCallFeedback\Lib\DialplanBuilder;
use Modules\ModuleCallFeedback\Lib\ExampleSurvey;
use Modules\ModuleCallFeedback\Lib\Migrations;
use PDO;
use Throwable;

/**
 * Installer. The base class creates the tables from the model annotations,
 * links agi-bin/ into Asterisk's AGI folder and adds the sidebar entry. The
 * built-in prompts in Sounds/ are installed and removed by the core when the
 * module is enabled and disabled.
 */
class PbxExtensionSetup extends PbxExtensionSetupBase
{
    /** The sidebar entry, in the modules group with a speech-bubbles icon. */
    public function addToSidebar(): bool
    {
        $key = "AdditionalMenuItem$this->moduleUniqueID";
        $menuSettings = PbxSettings::findFirstByKey($key) ?? new PbxSettings();
        $menuSettings->key = $key;
        $menuSettings->value = json_encode([
            'uniqid'        => $this->moduleUniqueID,
            'group'         => 'modules',
            'iconClass'     => 'comments outline',
            'caption'       => "Breadcrumb$this->moduleUniqueID",
            'showAtSidebar' => true,
        ]);

        return $menuSettings->save();
    }

    /**
     * Also removes what lives outside the module folder: the AGI link (the
     * core creates it on install but never removes it) and the module's astdb
     * families: the outbound talk-time hand-over and the frequency cap, which
     * holds phone numbers.
     */
    public function unInstallFiles(bool $keepSettings = false): bool
    {
        $link = Directories::getDir(Directories::AST_AGI_BIN_DIR) . '/' . DialplanBuilder::AGI_SCRIPT;
        if (is_link($link) && str_starts_with((string)readlink($link), $this->moduleDir . '/')) {
            unlink($link);
        }
        foreach ([DialplanBuilder::DB_TALK, DialplanBuilder::DB_CAP] as $family) {
            try {
                Util::getAstManager('off')->sendRequestTimeout('DBDelTree', ['Family' => $family]);
            } catch (Throwable $e) {
                SystemMessages::sysLogMsg(static::class, "Could not clear the astdb family $family: " . $e->getMessage(), LOG_WARNING);
            }
        }

        return parent::unInstallFiles($keepSettings);
    }

    /**
     * After the core creates or extends the tables: brings the database up to
     * the current schema (Migrations), then creates the example survey on a
     * new module database (ExampleSurvey decides; an update or a kept database
     * is left as it is). The core restores a kept database in installFiles(),
     * before this runs. A failed database update stops the install; a failed
     * example survey is only logged.
     */
    public function installDB(): bool
    {
        $file = $this->moduleDir . '/db/module.db';
        // On an update the core has already restored the kept database (installFiles()), so only a new install has none yet.
        $fresh = !is_file($file);
        if (!parent::installDB()) {
            return false;
        }
        $module = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        try {
            Migrations::run($module, $fresh);
        } catch (Throwable $e) {
            // Stop rather than run on a half-changed database; the step was rolled back.
            SystemMessages::sysLogMsg(static::class, 'Database update failed: ' . $e->getMessage(), LOG_ERR);
            $this->messages[] = 'Call Feedback could not update its database: ' . $e->getMessage();
            return false;
        }
        try {
            $config = CdrReader::openReadOnly((string)$this->di->getShared('config')->path('database.dbfile'));
            ExampleSurvey::createOnce($module, $config, (string)PbxSettings::getValueByKey(PbxSettings::PBX_LANGUAGE), time());
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Example survey not created: ' . $e->getMessage(), LOG_WARNING);
        }

        return true;
    }

    /**
     * Also removes the sidebar entry's setting, which the core leaves in its
     * settings table; installing adds it again.
     */
    public function unInstallDB(bool $keepSettings = false): bool
    {
        PbxSettings::findFirstByKey("AdditionalMenuItem$this->moduleUniqueID")?->delete();

        return parent::unInstallDB($keepSettings);
    }
}
