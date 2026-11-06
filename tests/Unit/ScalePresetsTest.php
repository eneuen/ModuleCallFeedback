<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use Modules\ModuleCallFeedback\Lib\ScalePresets;
use PHPUnit\Framework\TestCase;

final class ScalePresetsTest extends TestCase
{
    public function testScales(): void
    {
        self::assertSame([1, 2, 3, 4, 5], ScalePresets::values(ScalePresets::CSAT));
        self::assertSame(range(0, 10), ScalePresets::values(ScalePresets::NPS));
        self::assertSame(['1' => 1, '2' => 0], ScalePresets::keys(ScalePresets::YES_NO));
        self::assertSame([], ScalePresets::keys('stars10'));
    }

    public function testOnlyNpsWaitsForASecondDigit(): void
    {
        self::assertTrue(ScalePresets::needsDigitTimeout(ScalePresets::NPS));
        self::assertFalse(ScalePresets::needsDigitTimeout(ScalePresets::CSAT));
        self::assertFalse(ScalePresets::needsDigitTimeout(ScalePresets::YES_NO));
    }

    public function testYesNoIsNeverTheHeadline(): void
    {
        self::assertTrue(ScalePresets::canBeHeadline(ScalePresets::CES));
        self::assertFalse(ScalePresets::canBeHeadline(ScalePresets::YES_NO));
    }
}
