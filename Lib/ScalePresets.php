<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

/**
 * The answer scales a question can use. Scales are fixed presets, not free
 * ranges, so the keys a caller may press and the values stored for them are
 * always known, in the dialplan and in the worker alike.
 *
 * Higher is better on every scale, so "low score" means the same everywhere:
 * CES is asked as "how easy was it" (1 very hard, 5 very easy).
 */
final class ScalePresets
{
    public const string CSAT = 'csat5';
    public const string NPS = 'nps11';
    public const string CES = 'ces5';
    public const string YES_NO = 'yesno';

    /**
     * Key pressed => value stored, and whether the scale can be a survey's
     * headline score (question 1).
     */
    private const array PRESETS = [
        self::CSAT   => ['keys' => ['1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5], 'headline' => true],
        self::NPS    => ['keys' => ['0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6,
                                    '7' => 7, '8' => 8, '9' => 9, '10' => 10], 'headline' => true],
        self::CES    => ['keys' => ['1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5], 'headline' => true],
        // 1 = yes, 2 = no; stored as 1 and 0 so the average is the share of yes.
        self::YES_NO => ['keys' => ['1' => 1, '2' => 0], 'headline' => false],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::PRESETS);
    }

    public static function exists(string $scale): bool
    {
        return isset(self::PRESETS[$scale]);
    }

    public static function canBeHeadline(string $scale): bool
    {
        return self::PRESETS[$scale]['headline'] ?? false;
    }

    /** @return array<string, int> Key => value. */
    public static function keys(string $scale): array
    {
        return self::PRESETS[$scale]['keys'] ?? [];
    }

    /** @return list<int> */
    public static function values(string $scale): array
    {
        return array_values(self::keys($scale));
    }

    /**
     * NPS needs a digit timeout: "1" could be the start of "10", so Asterisk
     * waits for a second key before taking a lone 1.
     */
    public static function needsDigitTimeout(string $scale): bool
    {
        foreach (array_keys(self::keys($scale)) as $key) {
            if (strlen((string)$key) > 1) {
                return true;
            }
        }

        return false;
    }
}
