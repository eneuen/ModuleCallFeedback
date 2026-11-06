<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use Modules\ModuleCallFeedback\Lib\DialplanBuilder;
use PHPUnit\Framework\TestCase;

final class DialplanBuilderTest extends TestCase
{
    public function testTheInboundEntryIsAGosubThatReturns(): void
    {
        self::assertSame(
            "same => n,Gosub(call-feedback-in,s,1(SIP-TRUNK-2EC28F63))\n\t",
            DialplanBuilder::incomingAfterDial('SIP-TRUNK-2EC28F63')
        );
    }

    public function testContextIdsAreReducedToSafeCharacters(): void
    {
        self::assertSame(
            "same => n,Gosub(call-feedback-in,s,1(abcHangup))\n\t",
            DialplanBuilder::incomingAfterDial("a,b)c\n;Hangup()")
        );
        self::assertStringContainsString('1(none)', DialplanBuilder::incomingAfterDial(''));
    }

    public function testTheOutboundRouteGetsAnIntegerIdOnly(): void
    {
        $line = DialplanBuilder::outboundBeforeDial(['id' => '35); System(rm', 'providerid' => 'x']);

        self::assertStringContainsString('Set(__CF_OUT_ROUTE=35)', $line);
        self::assertStringContainsString('F(call-feedback-out^s^1)', $line);
        self::assertStringNotContainsString('System', $line);
    }

    public function testTheSurveyContextsAreNotRoutable(): void
    {
        $contexts = DialplanBuilder::contexts();

        self::assertStringNotContainsString('include =>', $contexts);
        self::assertStringNotContainsString('Dial(', $contexts, 'The survey must never place a call.');
        self::assertStringContainsString('AGI(' . DialplanBuilder::AGI_SCRIPT . ')', $contexts);
    }
}
