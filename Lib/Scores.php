<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

/**
 * How a headline score is worked out from the answers, per scale.
 *
 * - CSAT: the share of satisfied answers (4 or 5), in percent; the average too.
 * - NPS: % promoters (9-10) minus % detractors (0-6), from -100 to +100.
 *   "Net Promoter", "NPS" and "Net Promoter Score" are trademarks of Bain &
 *   Company, Satmetrix and Fred Reichheld; the page carries the notice.
 * - CES (asked as "how easy was it", 1-5): the share of easy answers (4 or 5).
 * - Yes/no: the share of yes.
 *
 * Pure PHP, unit-tested.
 */
final class Scores
{
    /**
     * @param list<int> $values Answers on one scale.
     *
     * @return array{responses: int, score: ?float, average: ?float, distribution: array<int, int>}
     */
    public static function summary(string $scale, array $values): array
    {
        $distribution = array_fill_keys(ScalePresets::values($scale), 0);
        foreach ($values as $value) {
            if (array_key_exists($value, $distribution)) {
                $distribution[$value]++;
            }
        }
        ksort($distribution);
        $n = array_sum($distribution);
        if ($n === 0) {
            return ['responses' => 0, 'score' => null, 'average' => null, 'distribution' => $distribution];
        }
        $sum = 0;
        foreach ($distribution as $value => $count) {
            $sum += $value * $count;
        }
        $share = fn (callable $in): float => 100 * array_sum(array_filter($distribution, $in, ARRAY_FILTER_USE_KEY)) / $n;

        $score = match ($scale) {
            ScalePresets::NPS    => $share(fn (int $v): bool => $v >= 9) - $share(fn (int $v): bool => $v <= 6),
            ScalePresets::YES_NO => $share(fn (int $v): bool => $v === 1),
            default              => $share(fn (int $v): bool => $v >= 4),
        };

        return [
            'responses'    => $n,
            'score'        => round($score, 1),
            'average'      => round($sum / $n, 2),
            'distribution' => $distribution,
        ];
    }

    /** Whether a headline answer is a detractor under the module's thresholds. */
    public static function isDetractor(string $scale, int $value, array $settings): bool
    {
        return match ($scale) {
            ScalePresets::CSAT => $value <= (int)$settings['csat_detractor_max'],
            ScalePresets::NPS  => $value <= (int)$settings['nps_detractor_max'],
            ScalePresets::CES  => $value <= (int)$settings['ces_detractor_max'],
            default            => false,
        };
    }
}
