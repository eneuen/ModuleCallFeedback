<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit\Support;

use Modules\ModuleCallFeedback\Lib\BuiltInPrompts;
use Modules\ModuleCallFeedback\Lib\FeedbackStore;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use PDO;

/**
 * Builds surveys the way the configuration pages do, through SurveyEditor.
 */
final class Surveys
{
    public const int NOW = 1793990000;

    /** Prompt IDs the fake sound-file lookup knows. */
    public const array PROMPTS = [11 => '/offload/asterisk/sounds/other/cf-intro', 12 => '/offload/asterisk/sounds/other/cf-q1',
        13 => '/offload/asterisk/sounds/other/cf-q2', 14 => '/offload/asterisk/sounds/other/cf-invalid',
        15 => '/offload/asterisk/sounds/other/cf-thanks'];

    public static function promptPath(int $id): ?string
    {
        return self::PROMPTS[$id] ?? null;
    }

    /** The fake sound files, and every built-in prompt as if the module shipped all of them. */
    public static function promptUsable(int $id, string $language = 'en-en'): bool
    {
        return isset(self::PROMPTS[$id]) || BuiltInPrompts::isBuiltIn($id);
    }

    /**
     * A complete draft: CSAT then a yes/no question, every prompt set, targeting
     * the given routes, with a test call already placed when $tested.
     *
     * @param list<array{direction: string, route_id: int}> $targets
     */
    public static function complete(SurveyEditor $editor, PDO $module, array $targets, bool $tested = true,
        string $headline = ScalePresets::CSAT, string $name = 'After-call survey'): int
    {
        $id = $editor->create($name, 'en-en', self::NOW);
        $editor->update($id, [
            'intro_prompt_id' => 11, 'invalid_prompt_id' => 14, 'thanks_prompt_id' => 15,
            'questions' => [
                ['scale' => $headline, 'prompt_id' => 12, 'timeout_seconds' => 6, 'retries' => 2],
                ['scale' => ScalePresets::YES_NO, 'prompt_id' => 13],
            ],
        ]);
        $editor->setTargets($id, $targets);
        if ($tested) {
            self::testCall($module, $id);
        }

        return $id;
    }

    /** Records a test session for a version, as a test call from the editor does. */
    public static function testCall(PDO $module, int $surveyId): void
    {
        $module->prepare('INSERT INTO ' . FeedbackStore::SESSIONS . " (linkedid, survey_id, direction, status, ended_at,
            excluded, is_test, attributed_at) VALUES (?, ?, 'in', 'completed', ?, 0, 1, ?)")
            ->execute(['test-' . $surveyId . '-' . random_int(1, PHP_INT_MAX), $surveyId, self::NOW, self::NOW]);
    }
}
