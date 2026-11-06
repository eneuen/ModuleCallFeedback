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

    public function testSkippedCallsAreCountedByReason(): void
    {
        $skip = FeedbackStoreTest::record(['status' => 'skipped', 'reason' => 'capped', 'answers' => [],
            'ended_at' => (new DateTimeImmutable('2026-11-04 10:00'))->getTimestamp()]);
        $this->store->record($skip, (new SurveyRepository($this->module))->find($this->survey));

        self::assertSame(1, $this->results()->overview($this->query())['skipped']['capped']);
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
