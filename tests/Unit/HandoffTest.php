<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallFeedback\Lib\Handoff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The end-of-call record: what the AGI script reads from the channel and what
 * the worker accepts.
 */
final class HandoffTest extends TestCase
{
    private const string LINKEDID = 'mikopbx-1793993854.585';

    /** Channel variables of a completed inbound survey, as the dialplan sets them. */
    private static function inboundVars(): array
    {
        return [
            'CF_V' => '1', 'CF_SURVEY' => '0', 'CF_DIR' => 'in', 'CF_STATUS' => 'completed', 'CF_REASON' => '',
            'CF_CTX' => 'SIP-TRUNK-2EC28F63', 'CF_ROUTE' => '', 'CF_DID' => '5551001', 'CF_CALLER' => '5550000100',
            'CF_ANSWER_AT' => '1793993857', 'CF_TALK' => '6', 'CF_OFFERED' => '1793993863',
            'CF_A1' => '4', 'CF_A1_AT' => '1793993865', 'CF_A2' => '10', 'CF_A2_AT' => '1793993867',
            'CF_A3' => '', 'CF_A3_AT' => '',
        ];
    }

    public function testBuildsARecordFromChannelVariables(): void
    {
        $record = Handoff::fromChannel(self::LINKEDID, self::inboundVars(), 1793993868);

        self::assertSame([
            'linkedid'   => self::LINKEDID,
            'survey'     => 0,
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
        ], $record);
    }

    public function testSurvivesTheQueueUnchanged(): void
    {
        $record = Handoff::fromChannel(self::LINKEDID, self::inboundVars(), 1793993868);

        self::assertSame($record, Handoff::decode(Handoff::encode($record)));
    }

    public function testASkippedCallCarriesItsReason(): void
    {
        $vars = ['CF_STATUS' => 'skipped', 'CF_REASON' => 'too_short', 'CF_A1' => '', 'CF_A2' => ''] + self::inboundVars();

        $record = Handoff::fromChannel(self::LINKEDID, $vars, 1793993868);

        self::assertSame('too_short', $record['reason']);
        self::assertSame([], $record['answers']);
    }

    public function testTheChannelVariableListCoversEveryQuestion(): void
    {
        self::assertContains('CF_A' . Handoff::MAX_QUESTIONS . '_AT', Handoff::channelVariables());
        self::assertContains('CF_CALLER', Handoff::channelVariables());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function rejectedVariables(): iterable
    {
        // Caller-controlled text must never get through, whatever the dialplan did.
        yield 'caller number with dialplan' => [['CF_CALLER' => '123;Hangup()']];
        yield 'caller number with markup' => [['CF_CALLER' => '"><img src=x onerror=alert(1)>']];
        yield 'newline in a field' => [['CF_DID' => "5551001\nexten => s,1,Hangup()"]];
        yield 'variable syntax in context' => [['CF_CTX' => '${SHELL(id)}']];
        yield 'unknown status' => [['CF_STATUS' => 'done']];
        yield 'unknown direction' => [['CF_DIR' => 'internal']];
        yield 'reason on a completed call' => [['CF_REASON' => 'too_short']];
        yield 'unknown skip reason' => [['CF_STATUS' => 'skipped', 'CF_REASON' => 'bored', 'CF_A1' => '', 'CF_A2' => '']];
        yield 'answers on a skipped call' => [['CF_STATUS' => 'skipped', 'CF_REASON' => 'too_short']];
        yield 'non-digit answer' => [['CF_A1' => '*']];
        yield 'three-digit answer' => [['CF_A2' => '100']];
        yield 'other record version' => [['CF_V' => '2']];
        yield 'missing version' => [['CF_V' => '']];
    }

    /** @param array<string, string> $override */
    #[DataProvider('rejectedVariables')]
    public function testRejectsValuesOutsideTheirPattern(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);
        Handoff::fromChannel(self::LINKEDID, $override + self::inboundVars(), 1793993868);
    }

    public function testRejectsAForgedLinkedid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Handoff::fromChannel("x' OR 1=1 --", self::inboundVars(), 1793993868);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedJson(): iterable
    {
        yield 'not JSON' => ['not json'];
        yield 'a list' => ['[1,2]'];
        yield 'numbers where strings belong' => [str_replace('"in"', '1', Handoff::encode(
            Handoff::fromChannel(self::LINKEDID, self::inboundVars(), 1793993868)
        ))];
        yield 'too many answers' => [json_encode(['answers' => [1 => [], 2 => [], 3 => [], 4 => []]])];
        yield 'answer for question 4' => [str_replace('"2":', '"4":', Handoff::encode(
            Handoff::fromChannel(self::LINKEDID, self::inboundVars(), 1793993868)
        ))];
    }

    #[DataProvider('malformedJson')]
    public function testTheWorkerRejectsMalformedRecords(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        Handoff::decode($json);
    }
}
