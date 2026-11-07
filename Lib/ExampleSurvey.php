<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use PDO;

/**
 * The example survey a new install starts with: a draft that uses the
 * built-in prompts, so an admin can place a test call within a minute of
 * installing the module.
 *
 * It is a draft with no routes, so it can't reach a caller until an admin
 * chooses routes, places a test call and activates it. It carries no results.
 * It is created once per module database: an update or a reinstall that keeps
 * the settings finds the marker and leaves things as they are, and a deleted
 * example never comes back.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class ExampleSurvey
{
    /** Bookkeeping in m_Settings, not a user setting. */
    public const string MARKER = 'example_created';

    private const array NAMES = ['en-en' => 'Example Survey', 'ru-ru' => 'Пример опроса'];

    /**
     * Creates the example unless this database has had one, or already has
     * surveys (an install from before the example existed).
     *
     * @param string $language The PBX's prompt language, such as "ru-ru"; anything else gives English.
     *
     * @return int|null The new survey's ID, or null when none was created.
     */
    public static function createOnce(PDO $module, PDO $config, string $language, int $now): ?int
    {
        $marker = $module->prepare('SELECT COUNT(*) FROM ' . ModuleSettings::TABLE . ' WHERE name = ?');
        $marker->execute([self::MARKER]);
        if ((int)$marker->fetchColumn() > 0) {
            return null;
        }
        $hasSurveys = (int)$module->query('SELECT COUNT(*) FROM ' . SurveyRepository::SURVEYS)->fetchColumn() > 0;

        $id = null;
        if (!$hasSurveys) {
            $language = isset(self::NAMES[$language]) ? $language : 'en-en';
            $editor = new SurveyEditor($module, $config);
            $id = $editor->create(self::NAMES[$language], $language, $now);
            $editor->update($id, [
                'intro_prompt_id'   => (string)BuiltInPrompts::id('intro'),
                'invalid_prompt_id' => (string)BuiltInPrompts::id('invalid'),
                'thanks_prompt_id'  => (string)BuiltInPrompts::id('thanks'),
                'questions'         => [
                    ['scale' => ScalePresets::CSAT, 'prompt_id' => (string)BuiltInPrompts::id('q-csat'), 'timeout_seconds' => '6', 'retries' => '2'],
                    ['scale' => ScalePresets::YES_NO, 'prompt_id' => (string)BuiltInPrompts::id('q-resolved'), 'timeout_seconds' => '6', 'retries' => '2'],
                ],
            ]);
        }
        $module->prepare('INSERT INTO ' . ModuleSettings::TABLE . ' (name, value) VALUES (?, ?)')
            ->execute([self::MARKER, (string)$now]);

        return $id;
    }
}
