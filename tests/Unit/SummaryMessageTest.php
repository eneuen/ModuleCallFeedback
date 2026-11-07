<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SummaryMessage;
use PHPUnit\Framework\TestCase;

final class SummaryMessageTest extends TestCase
{
    private static function overview(string $name, string $scale, int $responses, ?float $score): array
    {
        return ['survey' => ['name' => $name, 'version' => 2, 'scale' => $scale], 'min_sample' => 20,
            'score' => ['responses' => $responses, 'score' => $score], 'response_rate' => 61.5];
    }

    public function testListsEachSurveyAndTheOpenFollowUps(): void
    {
        $message = SummaryMessage::build('en-en', true, '2026-11-01', '2026-11-07', [
            self::overview("Support\r\nBcc: x@example.com", ScalePresets::NPS, 42, 12.0),
            self::overview('Sales', ScalePresets::CSAT, 5, 80.0),
        ], 3, 'https://pbx.example.com/admin-cabinet/module-call-feedback/index');

        self::assertSame('Call Feedback: weekly summary', $message['subject']);
        self::assertStringContainsString('Period: 2026-11-01 – 2026-11-07', $message['body']);
        self::assertStringContainsString('Support Bcc: x@example.com (v2): NPS +12, 42 responses, response rate 62%', $message['body']);
        self::assertStringContainsString('Sales (v2): 5 responses, too few for a score', $message['body']);
        self::assertStringContainsString('Open follow-ups: 3', $message['body']);
        self::assertStringEndsWith("https://pbx.example.com/admin-cabinet/module-call-feedback/index\n", $message['body']);
    }

    public function testRussianAndEmpty(): void
    {
        $message = SummaryMessage::build('ru-ru', false, '2026-11-06', '2026-11-06', [], 0, 'https://pbx/');

        self::assertSame('Оценка звонков: сводка за день', $message['subject']);
        self::assertStringContainsString('Период: 2026-11-06', $message['body']);
        self::assertStringContainsString('Активных опросов нет.', $message['body']);
    }
}
