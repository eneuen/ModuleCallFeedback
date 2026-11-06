<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use Modules\ModuleCallFeedback\Lib\FeedbackStore;
use Modules\ModuleCallFeedback\Lib\SurveyCatalog;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PHPUnit\Framework\TestCase;

final class FeedbackStoreTest extends TestCase
{
    private TestDatabases $databases;
    private FeedbackStore $store;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $this->store = new FeedbackStore($this->databases->moduleDatabase(), false);
    }

    /** A validated record (the shape Handoff::validate() returns). */
    public static function record(array $override = []): array
    {
        return $override + [
            'linkedid'   => 'mikopbx-1793993854.585',
            'survey'     => SurveyCatalog::BUILT_IN_ID,
            'direction'  => 'in',
            'status'     => 'completed',
            'reason'     => null,
            'context_id' => 'SIP-TRUNK-2EC28F63',
            'route_id'   => null,
            'did'        => '5551001',
            'caller'     => '5550000100',
            'answer_at'  => 1793993857,
            'talk'       => 6,
            'offered_at' => 1793993863,
            'ended_at'   => 1793993868,
            'answers'    => [1 => ['value' => 4, 'at' => 1793993865], 2 => ['value' => 10, 'at' => 1793993867]],
        ];
    }

    public function testStoresASessionWithItsAnswers(): void
    {
        self::assertSame(FeedbackStore::RECORDED, $this->store->record(self::record(), SurveyCatalog::builtIn()));

        $session = $this->store->session('mikopbx-1793993854.585', 0);
        self::assertNotNull($session);
        self::assertSame('completed', $session['status']);
        self::assertSame('5550000100', $session['caller_num']);
        self::assertSame(6, $session['talk_seconds']);
        self::assertNull($session['attributed_at']);
        self::assertSame([1 => 4, 2 => 10], $this->store->answersOf((int)$session['id']));
    }

    public function testASecondRecordForTheSameCallIsIgnored(): void
    {
        $this->store->record(self::record(), SurveyCatalog::builtIn());
        $forged = self::record(['answers' => [1 => ['value' => 1, 'at' => null], 2 => ['value' => 0, 'at' => null]]]);

        self::assertSame(FeedbackStore::DUPLICATE, $this->store->record($forged, SurveyCatalog::builtIn()));
        $session = $this->store->session('mikopbx-1793993854.585', 0);
        self::assertSame([1 => 4, 2 => 10], $this->store->answersOf((int)$session['id']));
    }

    public function testDropsAnswersOutsideTheQuestionsRange(): void
    {
        // CSAT is 1-5: 0 and 9 are not answers to it, whatever was pressed.
        $record = self::record(['answers' => [1 => ['value' => 9, 'at' => null], 2 => ['value' => 7, 'at' => null]]]);

        $this->store->record($record, SurveyCatalog::builtIn());

        $session = $this->store->session('mikopbx-1793993854.585', 0);
        self::assertSame([2 => 7], $this->store->answersOf((int)$session['id']));
        self::assertSame('started', $session['status'], 'The status follows the answers that were kept.');
    }

    public function testKeepsPartialAnswers(): void
    {
        $this->store->record(self::record(['status' => 'started', 'answers' => [1 => ['value' => 2, 'at' => null]]]), SurveyCatalog::builtIn());

        $session = $this->store->session('mikopbx-1793993854.585', 0);
        self::assertSame('started', $session['status']);
        self::assertSame([1 => 2], $this->store->answersOf((int)$session['id']));
    }

    public function testAnOfferedSurveyWithoutKeysIsStillASession(): void
    {
        $this->store->record(self::record(['status' => 'offered', 'answers' => []]), SurveyCatalog::builtIn());

        self::assertSame('offered', $this->store->session('mikopbx-1793993854.585', 0)['status']);
    }

    public function testCountsSkippedCallsPerDayRouteAndReason(): void
    {
        $skip = self::record(['status' => 'skipped', 'reason' => 'too_short', 'answers' => []]);
        $this->store->record($skip, SurveyCatalog::builtIn());
        $this->store->record(['linkedid' => 'mikopbx-2'] + $skip, SurveyCatalog::builtIn());
        $this->store->record(['direction' => 'out', 'route_id' => 35, 'context_id' => '', 'did' => ''] + $skip, SurveyCatalog::builtIn());

        $counts = $this->store->skipCounts();
        self::assertCount(2, $counts);
        self::assertSame('in:SIP-TRUNK-2EC28F63:5551001', $counts[0]['route_key']);
        self::assertSame(2, $counts[0]['count']);
        self::assertSame('out:35', $counts[1]['route_key']);
        self::assertSame(date('Y-m-d', 1793993868), $counts[0]['day']);
        self::assertNull($this->store->session('mikopbx-1793993854.585', 0), 'A skipped call is not a session.');
    }

    public function testIgnoresRecordsForAnUnknownSurvey(): void
    {
        self::assertSame(FeedbackStore::UNKNOWN_SURVEY, $this->store->record(self::record(['survey' => 99]), null));
        self::assertNull($this->store->session('mikopbx-1793993854.585', 99));
    }

    public function testAttributionAndDiscard(): void
    {
        $this->store->record(self::record(), SurveyCatalog::builtIn());
        $this->store->record(self::record(['linkedid' => 'mikopbx-2', 'ended_at' => 1793993900]), SurveyCatalog::builtIn());

        $pending = $this->store->pendingAttribution(1793993890, 10);
        self::assertSame(['mikopbx-1793993854.585'], array_column($pending, 'linkedid'), 'Only calls that ended in time.');

        $this->store->attribute((int)$pending[0]['id'], '301', null, 6, false, 1793993920);
        self::assertSame([], $this->store->pendingAttribution(1793993890, 10));
        $session = $this->store->session('mikopbx-1793993854.585', 0);
        self::assertSame('301', $session['agent']);
        self::assertSame(1793993920, $session['attributed_at']);

        $other = $this->store->session('mikopbx-2', 0);
        $this->store->discard((int)$other['id']);
        self::assertNull($this->store->session('mikopbx-2', 0));
        self::assertSame([], $this->store->answersOf((int)$other['id']));
    }
}
