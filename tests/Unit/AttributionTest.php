<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use Modules\ModuleCallFeedback\Lib\Attribution;
use Modules\ModuleCallFeedback\Lib\CallIndexer;
use Modules\ModuleCallFeedback\Lib\CdrReader;
use Modules\ModuleCallFeedback\Lib\FeedbackStore;
use Modules\ModuleCallFeedback\Lib\SurveyCatalog;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PHPUnit\Framework\TestCase;

/**
 * Attribution against CDR legs recorded from real calls (tests/fixtures/cdr).
 */
final class AttributionTest extends TestCase
{
    private TestDatabases $databases;
    private FeedbackStore $store;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $this->store = new FeedbackStore($this->databases->moduleDatabase(), false);
    }

    /** Stores a survey session for the call in a fixture, ending at $endedAt. */
    private function surveyed(string $scenario, string $direction, int $endedAt): string
    {
        $linkedId = (string)TestDatabases::legsOf($scenario)[0]['linkedid'];
        $this->store->record(FeedbackStoreTest::record([
            'linkedid' => $linkedId, 'direction' => $direction, 'ended_at' => $endedAt,
        ]), SurveyCatalog::builtIn());

        return $linkedId;
    }

    private function attribution(string ...$scenarios): Attribution
    {
        $cdr = new CdrReader($this->databases->cdrDatabase(TestDatabases::legsOf(...$scenarios)));

        return new Attribution($this->store, $cdr, new CallIndexer([TestDatabases::fixtureProviderId()]));
    }

    public function testFillsInTheAgentQueueAndTalkTime(): void
    {
        $direct = $this->surveyed('inbound_direct_answered', 'in', 1000);
        $queue = $this->surveyed('inbound_ivr_key1_queue_answered', 'in', 1000);
        $out = $this->surveyed('outbound_answered', 'out', 1000);

        $result = $this->attribution('inbound_direct_answered', 'inbound_ivr_key1_queue_answered', 'outbound_answered')
            ->run(1100);

        self::assertSame(['attributed' => 3, 'discarded' => 0, 'waiting' => 0], $result);
        $row = $this->store->session($direct, 0);
        self::assertSame(['301', null, 5, 0], [$row['agent'], $row['queue'], $row['cdr_talk_seconds'], $row['excluded']]);
        $row = $this->store->session($queue, 0);
        self::assertSame(['302', '3100', 5], [$row['agent'], $row['queue'], $row['cdr_talk_seconds']]);
        $row = $this->store->session($out, 0);
        self::assertSame('301', $row['agent'], 'Outbound: the extension that called.');
        self::assertSame(1100, $row['attributed_at']);
    }

    public function testWaitsForTheCdrToSettle(): void
    {
        $this->surveyed('inbound_direct_answered', 'in', 1000);

        $result = $this->attribution('inbound_direct_answered')->run(1000 + Attribution::SETTLE_SECONDS - 1);

        self::assertSame(0, $result['attributed']);
    }

    public function testMarksCallsUnderTheMinimumTalkTimeExcluded(): void
    {
        // The built-in survey's minimum is 4 s. The direct call talked 5 s; the
        // voicemail call, had it been surveyed, talked to nobody.
        $talked = $this->surveyed('inbound_direct_answered', 'in', 1000);
        $nobody = $this->surveyed('inbound_ivr_key3_voicemail', 'in', 1000);

        $this->attribution('inbound_direct_answered', 'inbound_ivr_key3_voicemail')->run(1100);

        self::assertSame(0, $this->store->session($talked, 0)['excluded']);
        $row = $this->store->session($nobody, 0);
        self::assertSame([1, 0, null], [$row['excluded'], $row['cdr_talk_seconds'], $row['agent']]);
    }

    public function testDiscardsSessionsWhoseCallNeverReachesTheCdr(): void
    {
        $this->store->record(FeedbackStoreTest::record(['linkedid' => 'forged-1', 'ended_at' => 1000]), SurveyCatalog::builtIn());
        $attribution = $this->attribution('inbound_direct_answered');

        self::assertSame(['attributed' => 0, 'discarded' => 0, 'waiting' => 1], $attribution->run(1100));
        self::assertNotNull($this->store->session('forged-1', 0), 'Kept while the CDR may still be catching up.');

        self::assertSame(1, $attribution->run(1000 + Attribution::GIVE_UP_SECONDS)['discarded']);
        self::assertNull($this->store->session('forged-1', 0));
    }
}
