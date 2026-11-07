<?php

// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Load benchmark: builds a module database holding a year of synthetic survey
 * sessions, then times the module page's own queries over it.
 *
 *   php tools/benchmark/run.php --per-day=200 --days=365 [--json=result.json]
 *
 * See README.md for the method and the results.
 */

declare(strict_types=1);

use Modules\ModuleCallFeedback\Lib\FollowUps;
use Modules\ModuleCallFeedback\Lib\Results;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use Modules\ModuleCallFeedback\Lib\SurveyRepository;
use Modules\ModuleCallFeedback\Tests\Unit\Support\Surveys;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;

require __DIR__ . '/../../vendor/autoload.php';

$options = getopt('', ['per-day::', 'days::', 'json::', 'runs::']);
$perDay = (int)($options['per-day'] ?? 200);
$days = (int)($options['days'] ?? 365);
$runs = (int)($options['runs'] ?? 7);
date_default_timezone_set('UTC');
mt_srand(20261107);

// ---------------------------------------------------------------- build the data

$databases = new TestDatabases();
$moduleFile = $databases->moduleDatabase();
$module = TestDatabases::writable($moduleFile);
$editor = new SurveyEditor($module, TestDatabases::writable($databases->configDatabase()));
$surveyId = Surveys::complete($editor, $module, [['direction' => 'in', 'route_id' => 40]]);
$editor->activate($surveyId, [Surveys::class, 'promptUsable'], Surveys::NOW);

$started = microtime(true);
$module->beginTransaction();
$session = $module->prepare('INSERT INTO m_SurveySessions (linkedid, survey_id, direction, status, context_id, route_id, did, caller_num,
    answer_at, talk_seconds, offered_at, ended_at, agent, queue, wait_seconds, cdr_talk_seconds, excluded, is_test, attributed_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)');
$answer = $module->prepare('INSERT INTO m_SurveyAnswers (session_id, position, value, answered_at) VALUES (?, ?, ?, ?)');
$followUp = $module->prepare("INSERT INTO m_FollowUps (session_id, status, created_at, closed_at) VALUES (?, ?, ?, ?)");
$skip = $module->prepare('INSERT INTO m_SkipCounts (day, survey_id, direction, route_key, reason, count) VALUES (?, ?, ?, ?, ?, ?)');

$today = (new DateTimeImmutable('today'))->getTimestamp();
$sessions = 0;
$csat = [1 => 6, 2 => 6, 3 => 14, 4 => 34, 5 => 40];     // percent per answer
$pick = static function (array $weights): int|string {
    $roll = mt_rand(1, array_sum($weights));
    foreach ($weights as $value => $weight) {
        if (($roll -= $weight) <= 0) {
            return $value;
        }
    }

    return array_key_last($weights);
};
for ($d = $days - 1; $d >= 0; $d--) {
    $dayStart = $today - $d * 86400;
    $weekend = (int)date('N', $dayStart) >= 6;
    $count = (int)round($perDay * ($weekend ? 0.25 : 1.0) * (0.85 + mt_rand(0, 30) / 100));
    for ($i = 0; $i < $count; $i++) {
        $ended = $dayStart + mt_rand(9 * 3600, 18 * 3600);
        $status = $pick(['completed' => 85, 'started' => 6, 'offered' => 9]);
        $direction = mt_rand(1, 100) <= 80 ? 'in' : 'out';
        $talk = mt_rand(25, 900);
        $session->execute([
            "bench-$d-$i", $surveyId, $direction, $status, 'SIP-TRUNK', 40, '55510' . mt_rand(0, 2), '555' . mt_rand(1000000, 9999999),
            $ended - $talk - 5, $talk, $ended - 30, $ended, (string)mt_rand(301, 310), mt_rand(0, 3) === 0 ? '' : (string)mt_rand(3100, 3102),
            mt_rand(2, 240), $talk, mt_rand(1, 100) <= 4 ? 1 : 0, $ended + 60,
        ]);
        $id = (int)$module->lastInsertId();
        $sessions++;
        if ($status === 'offered') {
            continue;
        }
        $score = $pick($csat);
        $answer->execute([$id, 1, $score, $ended - 20]);
        if ($status === 'completed') {
            $answer->execute([$id, 2, mt_rand(1, 100) <= 80 ? 1 : 0, $ended - 10]);
        }
        if ($score <= 2) {
            $state = $pick(['resolved' => 70, 'contacted' => 15, 'open' => 15]);
            $followUp->execute([$id, $state, $ended + 60, $state === 'resolved' ? $ended + mt_rand(3600, 3 * 86400) : null]);
        }
    }
    foreach (['too_short' => 0.12, 'outside_hours' => 0.05, 'capped' => 0.08, 'excluded' => 0.01] as $reason => $share) {
        $skip->execute([date('Y-m-d', $dayStart), $surveyId, 'in', 'in:SIP-TRUNK:5551000', $reason, (int)round($count * $share)]);
    }
}
$module->commit();
$built = microtime(true) - $started;
$module->exec('ANALYZE');

// ---------------------------------------------------------------- measure

$results = new Results($module, 20);
$followUps = new FollowUps($module);
$repository = new SurveyRepository($module);
$query = static fn (int $span): array => [
    'survey' => (string)$surveyId, 'from' => date('Y-m-d', $today - ($span - 1) * 86400), 'to' => date('Y-m-d', $today), 'direction' => '',
];
$time = static function (callable $work) use ($runs): array {
    $samples = [];
    for ($i = 0; $i < $runs; $i++) {
        $t = hrtime(true);
        $work();
        $samples[] = (hrtime(true) - $t) / 1e6;
    }
    sort($samples);

    return ['p50' => round($samples[intdiv(count($samples), 2)], 1), 'p95' => round($samples[(int)ceil(0.95 * count($samples)) - 1], 1)];
};

$timings = [];
foreach ([30, 90, 365] as $span) {
    $q = $query(min($span, $days));
    $timings["overview, last $span days"] = $time(fn () => $results->overview($q));
    $timings["survey health, last $span days"] = $time(fn () => $results->health($q));
    $slowest = ['p50' => 0, 'p95' => 0];
    foreach (Results::BREAKDOWNS as $kind) {
        $t = $time(fn () => $results->breakdown($q + ['breakdown' => $kind]));
        $slowest = $t['p95'] > $slowest['p95'] ? $t + ['kind' => $kind] : $slowest;
    }
    $timings["slowest breakdown, last $span days"] = $slowest;
}
$timings['follow-ups, first page of all'] = $time(fn () => $followUps->list('', 1));
$timings['survey list'] = $time(fn () => $repository->listing($today - 7 * 86400));

$summary = [
    'per_day' => $perDay, 'days' => $days, 'sessions' => $sessions,
    'answers' => (int)$module->query('SELECT COUNT(*) FROM m_SurveyAnswers')->fetchColumn(),
    'follow_ups' => (int)$module->query('SELECT COUNT(*) FROM m_FollowUps')->fetchColumn(),
    'database_mb' => round(filesize($moduleFile) / 1048576, 1),
    'build_seconds' => round($built, 1), 'php' => PHP_VERSION, 'timings_ms' => $timings,
    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
];
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
if (isset($options['json'])) {
    file_put_contents((string)$options['json'], json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
