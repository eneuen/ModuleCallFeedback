<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

/**
 * The daily or weekly summary email, as plain text in the PBX's language
 * (English or Russian): each active survey's score, responses and response
 * rate, and the follow-ups still open. No caller data; survey names lose
 * line breaks, and the subject is fixed text.
 *
 * Pure PHP, unit-tested.
 */
final class SummaryMessage
{
    private const array TEXT = [
        'en' => [
            'subject_daily'  => 'Call Feedback: daily summary',
            'subject_weekly' => 'Call Feedback: weekly summary',
            'period'         => 'Period',
            'score'          => 'score',
            'responses'      => 'responses',
            'response_rate'  => 'response rate',
            'too_few'        => 'not enough responses yet',
            'none'           => 'No active surveys.',
            'open'           => 'Open follow-ups',
            'more'           => 'Details: Call Feedback in the PBX web interface',
        ],
        'ru' => [
            'subject_daily'  => 'Оценка звонков: сводка за день',
            'subject_weekly' => 'Оценка звонков: сводка за неделю',
            'period'         => 'Период',
            'score'          => 'оценка',
            'responses'      => 'ответов',
            'response_rate'  => 'доля ответивших',
            'too_few'        => 'пока мало ответов',
            'none'           => 'Активных опросов нет.',
            'open'           => 'Открытых обращений',
            'more'           => 'Подробнее: «Оценка звонков» в веб-интерфейсе АТС',
        ],
    ];

    /**
     * @param list<array<string, mixed>> $overviews Results::overview() of each active survey.
     *
     * @return array{subject: string, body: string}
     */
    public static function build(string $pbxLanguage, bool $weekly, string $from, string $to, array $overviews, int $openFollowUps): array
    {
        $text = self::TEXT[str_starts_with($pbxLanguage, 'ru') ? 'ru' : 'en'];
        $lines = ["{$text['period']}: " . ($from === $to ? $from : "$from – $to"), ''];
        if ($overviews === []) {
            $lines[] = $text['none'];
        }
        foreach ($overviews as $o) {
            $name = trim(preg_replace('/[\r\n\t\x00-\x1F\x7F]+/u', ' ', (string)$o['survey']['name']) ?? '');
            $score = $o['score']['responses'] < $o['min_sample'] || $o['score']['score'] === null
                ? $text['too_few']
                : self::scoreText((string)$o['survey']['scale'], (float)$o['score']['score']);
            $rate = $o['response_rate'] === null ? '—' : $o['response_rate'] . '%';
            $lines[] = "$name (v{$o['survey']['version']}): {$text['score']} $score, {$o['score']['responses']} {$text['responses']}, {$text['response_rate']} $rate";
        }
        $lines[] = '';
        $lines[] = "{$text['open']}: $openFollowUps";
        $lines[] = '';
        $lines[] = $text['more'];
        $lines[] = AlertMessage::PAGE_PATH;

        return ['subject' => $text[$weekly ? 'subject_weekly' : 'subject_daily'], 'body' => implode("\n", $lines) . "\n"];
    }

    private static function scoreText(string $scale, float $score): string
    {
        $value = rtrim(rtrim(number_format($score, 1, '.', ''), '0'), '.');

        return match ($scale) {
            ScalePresets::NPS => 'NPS ' . ($score > 0 ? '+' : '') . $value,
            ScalePresets::CES => "CES $value%",
            default           => "CSAT $value%",
        };
    }
}
