<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use Modules\ModuleCallFeedback\Lib\ModuleSettings;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\Scores;
use PHPUnit\Framework\TestCase;

final class ScoresTest extends TestCase
{
    public function testNpsIsPromotersMinusDetractors(): void
    {
        // 3 promoters (9, 10, 10), 2 passives (7, 8), 5 detractors (0-6): 30% - 50% = -20.
        $summary = Scores::summary(ScalePresets::NPS, [9, 10, 10, 7, 8, 0, 3, 5, 6, 6]);

        self::assertSame(10, $summary['responses']);
        self::assertSame(-20.0, $summary['score']);
        self::assertSame(2, $summary['distribution'][6]);
        self::assertCount(11, $summary['distribution']);
    }

    public function testCsatIsTheShareOfFoursAndFives(): void
    {
        $summary = Scores::summary(ScalePresets::CSAT, [5, 4, 4, 3, 1]);

        self::assertSame(60.0, $summary['score']);
        self::assertSame(3.4, $summary['average']);
    }

    public function testYesNoIsTheShareOfYes(): void
    {
        self::assertSame(75.0, Scores::summary(ScalePresets::YES_NO, [1, 1, 1, 0])['score']);
    }

    public function testNoAnswersMeansNoScore(): void
    {
        $summary = Scores::summary(ScalePresets::NPS, []);

        self::assertSame([0, null, null], [$summary['responses'], $summary['score'], $summary['average']]);
    }

    public function testValuesOffTheScaleAreIgnored(): void
    {
        self::assertSame(1, Scores::summary(ScalePresets::CSAT, [0, 9, 5])['responses']);
    }

    public function testDetractorThresholds(): void
    {
        $settings = ModuleSettings::defaults();

        self::assertTrue(Scores::isDetractor(ScalePresets::NPS, 6, $settings));
        self::assertFalse(Scores::isDetractor(ScalePresets::NPS, 7, $settings));
        self::assertTrue(Scores::isDetractor(ScalePresets::CSAT, 2, $settings));
        self::assertFalse(Scores::isDetractor(ScalePresets::CSAT, 3, $settings));
        self::assertFalse(Scores::isDetractor(ScalePresets::YES_NO, 0, $settings), 'Only headline scores open follow-ups.');
    }
}
