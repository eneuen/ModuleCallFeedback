<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

/**
 * Survey definitions: the questions, their key ranges and the minimum talk
 * time. The dialplan and the worker read the same definition, so an answer is
 * stored only if it is inside the range the caller was asked for.
 *
 * Until surveys can be configured, there is one built-in test survey (ID 0).
 */
final class SurveyCatalog
{
    public const int BUILT_IN_ID = 0;

    /**
     * @return array{id: int, min_talk_seconds: int, questions: array<int, array{metric: string, min: int, max: int}>}
     */
    public static function builtIn(): array
    {
        return [
            'id'               => self::BUILT_IN_ID,
            'min_talk_seconds' => 4,
            'questions'        => [
                1 => ['metric' => 'csat', 'min' => 1, 'max' => 5],
                2 => ['metric' => 'nps', 'min' => 0, 'max' => 10],
            ],
        ];
    }

    /**
     * @return array{id: int, min_talk_seconds: int, questions: array<int, array{metric: string, min: int, max: int}>}|null
     */
    public static function find(int $surveyId): ?array
    {
        return $surveyId === self::BUILT_IN_ID ? self::builtIn() : null;
    }
}
