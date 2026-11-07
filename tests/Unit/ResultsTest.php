<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\ModuleCallFeedback\Lib\FeedbackStore;
use Modules\ModuleCallFeedback\Lib\Results;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use Modules\ModuleCallFeedback\Lib\SurveyRepository;
use Modules\ModuleCallFeedback\Tests\Unit\Support\Surveys;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResultsTest extends TestCase
{
    private TestDatabases $databases;
    private PDO $module;
    private FeedbackStore $store;
    private int $survey;
    private int $n = 0;

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Moscow');
        $this->databases = new TestDatabases();
        $file = $this->databases->moduleDatabase();
        $this->module = TestDatabases::writable($file);
        $this->store = new FeedbackStore($file, false);
        $editor = new SurveyEditor($this->module, TestDatabases::writable($this->databases->configDatabase()));
        // NPS, then "was your issue resolved?"
        $this->survey = Surveys::complete($editor, $this->module, [['direction' => 'in', 'route_id' => 40]], headline: ScalePresets::NPS);
        $editor->activate($this->survey, [Surveys::class, 'promptUsable'], Surveys::NOW);
    }

    /** A stored session ending at $when (local time), with the given answers. */
    private function session(string $when, array $answers, array $fields = []): void
    {
        $this->n++;
        $record = FeedbackStoreTest::record([
            'linkedid' => "call-$this->n", 'survey' => $this->survey, 'ended_at' => (new DateTimeImmutable($when))->getTimestamp(),
            'status' => 'completed',
            'answers' => array_map(fn (int $v): array => ['value' => $v, 'at' => null], $answers),
        ] + $fields);
        $this->store->record($record, (new SurveyRepository($this->module))->find($this->survey));
    }

    private function results(int $minSample = 2): Results
    {
        return new Results($this->module, $minSample);
    }

    private function query(array $extra = []): array
    {
        return $extra + ['survey' => (string)$this->survey, 'from' => '2026-11-02', 'to' => '2026-11-08', 'direction' => ''];
    }

    public function testOverview(): void
    {
        $this->session('2026-11-03 10:00', [1 => 10, 2 => 1]);
        $this->session('2026-11-03 11:00', [1 => 9, 2 => 1]);
        $this->session('2026-11-04 12:00', [1 => 3, 2 => 0]);
        $this->session('2026-11-05 09:00', [1 => 8]);                       // hung up before question 2
        $this->session('2026-11-05 09:30', [], ['status' => 'offered']);   // pressed nothing
        $this->session('2026-10-28 10:00', [1 => 2]);                       // previous week
        $this->session('2026-11-06 10:00', [1 => 10], ['is_test' => true]); // a test call
        $this->module->exec("UPDATE m_SurveySessions SET excluded = 1 WHERE linkedid = 'call-1'");

        $o = $this->results()->overview($this->query());

        self::assertSame([4, 3, 2, 1], [$o['offered'], $o['started'], $o['completed'], $o['excluded']]);
        // Counted: 9, 3, 8 => 1 promoter, 1 detractor of 3: 0.
        self::assertSame(3, $o['score']['responses']);
        self::assertSame(0.0, $o['score']['score']);
        self::assertSame(-100.0, $o['previous']['score']['score']);
        self::assertSame(['2026-10-26', '2026-11-01'], [$o['previous']['from'], $o['previous']['to']]);
        self::assertSame(75.0, $o['response_rate']);
        self::assertSame(66.7, $o['completion_rate']);
        self::assertSame(50.0, $o['resolution']['score'], 'One yes, one no among the counted sessions.');
        self::assertSame('day', $o['trend']['interval']);
        self::assertCount(7, $o['trend']['points']);
        self::assertSame(['period' => '2026-11-03', 'responses' => 1, 'score' => 100.0, 'low_sample' => true], $o['trend']['points'][1]);
    }

    public function testOverviewCountsThePeriodsFollowUps(): void
    {
        $this->session('2026-11-03 10:00', [1 => 2]);
        $this->session('2026-11-04 10:00', [1 => 3]);
        $this->session('2026-11-05 10:00', [1 => 1], ['is_test' => true]);
        $this->session('2026-10-28 10:00', [1 => 0]); // previous week
        $now = Surveys::NOW;
        foreach ($this->module->query('SELECT id FROM m_SurveySessions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $i => $id) {
            $status = $i === 1 ? 'resolved' : 'open';
            $this->module->exec("INSERT INTO m_FollowUps (session_id, status, created_at) VALUES ($id, '$status', $now)");
        }

        $o = $this->results()->overview($this->query());

        self::assertSame(['open' => 1, 'contacted' => 0, 'resolved' => 1], $o['followups'], 'Test calls and other periods are left out.');
    }

    public function testThinDailyTrendsGoWeekly(): void
    {
        $this->session('2026-10-20 10:00', [1 => 10]);
        $this->session('2026-11-03 10:00', [1 => 9]);

        $o = $this->results()->overview($this->query(['from' => '2026-10-10', 'to' => '2026-11-08']));

        self::assertSame('week', $o['trend']['interval'], 'Two responses over 30 days: far below 2 a day.');

        for ($i = 0; $i < 30; $i++) {
            $this->session('2026-11-05 10:00', [1 => 10]);
            $this->session('2026-11-06 10:00', [1 => 10]);
        }
        $o = $this->results()->overview($this->query(['from' => '2026-10-10', 'to' => '2026-11-08']));

        self::assertSame('day', $o['trend']['interval'], '62 responses over 30 days: enough for a daily line.');
    }

    public function testLongRangesTrendByWeek(): void
    {
        $o = $this->results()->overview($this->query(['from' => '2026-08-01', 'to' => '2026-11-08']));

        self::assertSame('week', $o['trend']['interval']);
        self::assertSame('2026-07-27', $o['trend']['points'][0]['period'], 'Weeks start on Monday.');
    }

    public function testBreakdownByAgentAndHour(): void
    {
        $this->session('2026-11-03 10:15', [1 => 10]);
        $this->session('2026-11-03 10:45', [1 => 0]);
        $this->session('2026-11-04 15:00', [1 => 9]);
        $this->module->exec("UPDATE m_SurveySessions SET agent = '301' WHERE linkedid IN ('call-1', 'call-2')");
        $this->module->exec("UPDATE m_SurveySessions SET agent = '302' WHERE linkedid = 'call-3'");

        $agents = $this->results()->breakdown($this->query(['breakdown' => 'agent']));
        self::assertSame([['key' => '301', 'responses' => 2, 'score' => 0.0, 'average' => 5.0, 'low_sample' => false],
            ['key' => '302', 'responses' => 1, 'score' => 100.0, 'average' => 9.0, 'low_sample' => true]], $agents['rows']);

        $hours = $this->results()->breakdown($this->query(['breakdown' => 'hour']));
        self::assertSame(['10', '15'], array_column($hours['rows'], 'key'));
    }

    public function testHealthShowsHowFarCallersGot(): void
    {
        $this->session('2026-11-03 10:00', [1 => 10, 2 => 1]);
        $this->session('2026-11-03 11:00', [1 => 9, 2 => 0]);
        $this->session('2026-11-04 12:00', [1 => 3]);                        // left at question 2
        $this->session('2026-11-05 09:00', [], ['status' => 'offered']);    // left at question 1
        $this->session('2026-11-05 10:00', [2 => 1]);                        // missed question 1, answered the last
        $this->session('2026-11-05 11:00', [1 => 8, 2 => 1]);
        $this->module->exec("UPDATE m_SurveySessions SET excluded = 1 WHERE linkedid = 'call-6'");
        $this->skip('too_short');
        $this->skip('too_short');
        $this->skip('capped');
        $this->skip('capped', 999);

        $h = $this->results()->health($this->query());

        self::assertSame(9, $h['eligible'], 'Six sessions and three skips of this version.');
        self::assertSame([5, 3, 1], [$h['surveyed'], $h['finished'], $h['excluded']]);
        self::assertSame(['too_short' => 2, 'capped' => 1], array_filter($h['skipped']), 'Another version\'s skips are left out.');
        self::assertSame([5, 3, 1, 20.0], array_values(array_diff_key($h['questions'][0], array_flip(['position', 'scale']))));
        self::assertSame([4, 3, 1, 25.0], array_values(array_diff_key($h['questions'][1], array_flip(['position', 'scale']))));
        self::assertSame(ScalePresets::YES_NO, $h['questions'][1]['scale']);
    }

    /** A call this version's rules (or another version's) left unasked, on 4 November. */
    private function skip(string $reason, ?int $surveyId = null): void
    {
        $this->n++;
        $survey = (new SurveyRepository($this->module))->find($this->survey);
        $this->store->record(FeedbackStoreTest::record(['linkedid' => "skip-$this->n", 'status' => 'skipped', 'reason' => $reason, 'answers' => [],
            'ended_at' => (new DateTimeImmutable('2026-11-04 10:00'))->getTimestamp()]), $surveyId === null ? $survey : ['id' => $surveyId] + $survey);
    }

    public function testBreakdownByWaitAndTalkTime(): void
    {
        $this->session('2026-11-03 10:00', [1 => 10]);
        $this->session('2026-11-03 11:00', [1 => 0]);
        $this->session('2026-11-04 12:00', [1 => 9]);
        $this->module->exec("UPDATE m_SurveySessions SET wait_seconds = 10, cdr_talk_seconds = 400 WHERE linkedid = 'call-1'");
        $this->module->exec("UPDATE m_SurveySessions SET wait_seconds = 200, cdr_talk_seconds = 50 WHERE linkedid = 'call-2'");

        $wait = $this->results()->breakdown($this->query(['breakdown' => 'wait']));
        self::assertSame(['0', '120', ''], array_column($wait['rows'], 'key'), 'By bucket, unknown last.');
        self::assertSame([100.0, -100.0], [$wait['rows'][0]['score'], $wait['rows'][1]['score']]);

        $talk = $this->results()->breakdown($this->query(['breakdown' => 'talk']));
        self::assertSame(['0', '300', ''], array_column($talk['rows'], 'key'));
    }

    public function testSkippedCallsAreCountedByReason(): void
    {
        $this->skip('capped');
        $this->skip('capped', 999);

        self::assertSame(1, $this->results()->overview($this->query())['skipped']['capped'], 'Only this version\'s skips.');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function badQueries(): iterable
    {
        yield 'no survey' => [['survey' => '']];
        yield 'unknown survey' => [['survey' => '999']];
        yield 'bad date' => [['from' => '2026-13-01']];
        yield 'reversed range' => [['from' => '2026-11-08', 'to' => '2026-11-01']];
        yield 'too long' => [['from' => '2024-01-01']];
        yield 'unknown direction' => [['direction' => 'internal']];
        yield 'injection' => [['survey' => '1 OR 1=1']];
    }

    #[DataProvider('badQueries')]
    public function testRejectsBadQueries(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->results()->overview($override + $this->query());
    }

    public function testUnknownBreakdownsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->results()->breakdown($this->query(['breakdown' => 'caller_num']));
    }

    public function testOnlyVersionsWithResultsAreListed(): void
    {
        $versions = $this->results()->versions();

        self::assertSame([[$this->survey, 'active', ScalePresets::NPS]], array_map(fn (array $v): array => [$v['id'], $v['status'], $v['scale']], $versions));
    }
}
