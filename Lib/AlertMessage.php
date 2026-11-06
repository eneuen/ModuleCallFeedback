<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

/**
 * The detractor alert email, as plain text, in the PBX's language (English
 * or Russian).
 *
 * It says which survey, the score and when, and where to find the case. It
 * never carries the caller's number or name: those stay behind the admin
 * login. The subject is fixed text, and the survey name loses line breaks,
 * so nothing typed anywhere can add a mail header or fake a second message.
 *
 * Pure PHP, unit-tested.
 */
final class AlertMessage
{
    public const string PAGE_PATH = '/admin-cabinet/module-call-feedback/index?tab=followups';

    private const array TEXT = [
        'en' => [
            'subject' => 'Call Feedback: a caller gave a low score',
            'intro'   => 'A caller gave a low score after a call.',
            'survey'  => 'Survey',
            'score'   => 'Score',
            'agent'   => 'Agent',
            'time'    => 'Time',
            'open'    => 'Open Call Feedback → Follow-ups in the PBX web interface to call them back:',
        ],
        'ru' => [
            'subject' => 'Оценка звонков: звонящий поставил низкую оценку',
            'intro'   => 'После звонка звонящий поставил низкую оценку.',
            'survey'  => 'Опрос',
            'score'   => 'Оценка',
            'agent'   => 'Сотрудник',
            'time'    => 'Время',
            'open'    => 'Откройте «Оценка звонков» → «Обращения» в веб-интерфейсе АТС, чтобы перезвонить:',
        ],
    ];

    private const array SCALE_RANGE = [ScalePresets::CSAT => '1–5', ScalePresets::NPS => '0–10', ScalePresets::CES => '1–5'];

    /**
     * @return array{subject: string, body: string}
     */
    public static function build(string $pbxLanguage, string $surveyName, string $scale, int $score, ?string $agent, int $endedAt): array
    {
        $text = self::TEXT[str_starts_with($pbxLanguage, 'ru') ? 'ru' : 'en'];
        $name = trim(preg_replace('/[\r\n\t\x00-\x1F\x7F]+/u', ' ', $surveyName) ?? '');
        $lines = [
            $text['intro'],
            '',
            "{$text['survey']}: $name",
            "{$text['score']}: $score (" . (self::SCALE_RANGE[$scale] ?? '') . ')',
        ];
        if ($agent !== null && preg_match('/^[0-9]{1,12}$/', $agent)) {
            $lines[] = "{$text['agent']}: $agent";
        }
        $lines[] = "{$text['time']}: " . date('Y-m-d H:i', $endedAt);
        $lines[] = '';
        $lines[] = $text['open'];
        $lines[] = self::PAGE_PATH;

        return ['subject' => $text['subject'], 'body' => implode("\n", $lines) . "\n"];
    }
}
