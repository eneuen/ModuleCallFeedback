<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;

/**
 * Ready-made surveys to start from: a headline question and "was your issue
 * resolved?", with the built-in intro, invalid-key and thank-you prompts, in
 * the survey's language. A survey made from one is an ordinary draft with no
 * routes; everything in it can be changed before it goes live.
 *
 * The example survey a new install starts with is the CSAT template.
 *
 * Plain PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class SurveyTemplates
{
    /** Template key => the headline question's scale and built-in prompt, in display order. */
    public const array TEMPLATES = [
        'csat' => [ScalePresets::CSAT, 'q-csat'],
        'nps'  => [ScalePresets::NPS, 'q-nps'],
        'ces'  => [ScalePresets::CES, 'q-ces'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /**
     * Creates a draft from a template.
     *
     * @throws InvalidArgumentException For an unknown template, or a name or language the editor refuses.
     */
    public static function create(SurveyEditor $editor, string $template, string $name, string $language, int $now): int
    {
        if (!isset(self::TEMPLATES[$template])) {
            throw new InvalidArgumentException('Unknown survey template.');
        }
        [$scale, $prompt] = self::TEMPLATES[$template];
        $id = $editor->create($name, $language, $now);
        $editor->update($id, [
            'intro_prompt_id'   => (string)BuiltInPrompts::id('intro'),
            'invalid_prompt_id' => (string)BuiltInPrompts::id('invalid'),
            'thanks_prompt_id'  => (string)BuiltInPrompts::id('thanks'),
            'questions'         => [
                ['scale' => $scale, 'prompt_id' => (string)BuiltInPrompts::id($prompt), 'timeout_seconds' => '6', 'retries' => '2'],
                ['scale' => ScalePresets::YES_NO, 'prompt_id' => (string)BuiltInPrompts::id('q-resolved'), 'timeout_seconds' => '6', 'retries' => '2'],
            ],
        ]);

        return $id;
    }
}
