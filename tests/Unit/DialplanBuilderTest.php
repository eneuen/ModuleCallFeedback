<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use Modules\ModuleCallFeedback\Lib\DialplanBuilder;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use Modules\ModuleCallFeedback\Tests\Unit\Support\Surveys;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class DialplanBuilderTest extends TestCase
{
    private TestDatabases $databases;
    private PDO $module;
    private PDO $config;
    private SurveyEditor $editor;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $this->module = TestDatabases::writable($this->databases->moduleDatabase());
        $this->config = TestDatabases::writable($this->databases->configDatabase());
        $this->editor = new SurveyEditor($this->module, $this->config);
    }

    private function builder(): DialplanBuilder
    {
        return DialplanBuilder::fromDatabases($this->module, $this->config, [Surveys::class, 'promptPath']);
    }

    private function active(array $targets, string $headline = ScalePresets::CSAT): int
    {
        $id = Surveys::complete($this->editor, $this->module, $targets, headline: $headline);
        $this->editor->activate($id, [Surveys::class, 'promptUsable'], Surveys::NOW);

        return $id;
    }

    public function testNothingIsAddedWithoutSurveys(): void
    {
        $builder = $this->builder();

        self::assertSame('', $builder->contexts());
        self::assertSame('', $builder->incomingAfterDial());
        self::assertSame('', $builder->internalUsersPreDial());
        self::assertSame('', $builder->outboundBeforeDial(['id' => 35]));
    }

    public function testADraftCanOnlyBeReachedByATestCall(): void
    {
        $id = Surveys::complete($this->editor, $this->module, [['direction' => 'in', 'route_id' => 40]]);
        $builder = $this->builder();

        self::assertStringContainsString('[call-feedback-survey-' . $id . ']', $builder->contexts());
        self::assertStringContainsString("[call-feedback-test]\nexten => _X!,1,", $builder->contexts(), 'Matches one-digit IDs too.');
        self::assertSame('', $builder->incomingAfterDial(), 'A draft runs on no route.');
        self::assertStringNotContainsString('[call-feedback-in-match]', $builder->contexts());
    }

    public function testInboundTargetsAreMatchedMostSpecificFirst(): void
    {
        $any = $this->active([['direction' => 'in', 'route_id' => 42]]);
        $trunk = $this->active([['direction' => 'in', 'route_id' => 41]]);
        $direct = $this->active([['direction' => 'in', 'route_id' => 40]]);
        $builder = $this->builder();

        self::assertSame("same => n,Gosub(call-feedback-in,s,1)\n\t", $builder->incomingAfterDial());
        self::assertStringContainsString('call-feedback-agent-in-end', $builder->internalUsersPreDial());
        preg_match('/\[call-feedback-in-match\]\n(.*?)\n\n/s', $builder->contexts(), $m);
        self::assertSame(
            "exten => s,1,GotoIf(\$[\"\${providerID}\" == \"SIP-TRUNK-2EC28F63\" & \"\${FROM_DID}\" == \"5551001\"]?call-feedback-survey-$direct,s,1)\n"
            . "\tsame => n,GotoIf(\$[\"\${providerID}\" == \"SIP-TRUNK-2EC28F63\"]?call-feedback-survey-$trunk,s,1)\n"
            . "\tsame => n,GotoIf(\$[1]?call-feedback-survey-$any,s,1)\n"
            . "\tsame => n,Verbose(3,CFPROBE event=skip reason=not_targeted linkedid=\${CHANNEL(linkedid)} chan=\${CHANNEL})\n"
            . "\tsame => n,Return()",
            $m[1]
        );
    }

    public function testOnlyTargetedOutboundRoutesGetTheSurvey(): void
    {
        $id = $this->active([['direction' => 'out', 'route_id' => 35]]);
        $builder = $this->builder();

        $line = $builder->outboundBeforeDial(['id' => '35', 'providerid' => 'SIP-TRUNK-2EC28F63']);
        self::assertStringContainsString('Set(DOPTIONS=${DOPTIONS}F(call-feedback-out^s^1))', $line);
        self::assertStringContainsString("Set(__CF_OUT_SURVEY=$id)", $line);
        self::assertSame('', $builder->outboundBeforeDial(['id' => 21]));
        self::assertSame('', $builder->incomingAfterDial(), 'No inbound target, no inbound hook.');
    }

    public function testTheSurveyAppliesItsRulesThenAsksEachQuestion(): void
    {
        $id = $this->active([['direction' => 'in', 'route_id' => 40]]);
        $this->editor->update($id, ['sample_percent' => '25', 'min_talk_seconds' => '30', 'frequency_cap_days' => '7']);
        $conf = $this->builder()->contexts();

        preg_match("/\\[call-feedback-survey-$id\\]\n(.*?)\n\n/s", $conf, $m);
        $survey = $m[1];
        self::assertStringContainsString('GotoIf($[0${CF_TALK} < 30]?too_short)', $survey);
        self::assertStringContainsString('${DB(call-feedback-cap/${CF_NUM})}', $survey);
        self::assertStringContainsString('< 604800]?capped)', $survey);
        self::assertStringContainsString('GotoIf($[${RAND(1,100)} > 25]?sampled_out)', $survey);
        self::assertStringContainsString('GotoIf($[${GROUP_COUNT(surveys@call-feedback)} > 20]?concurrency)', $survey);
        self::assertStringContainsString('Set(CHANNEL(language)=en-en)', $survey);
        self::assertStringContainsString('Playback(/offload/asterisk/sounds/other/cf-intro)', $survey);
        self::assertStringContainsString("Gosub(call-feedback-q-$id-1,s,1)", $survey);
        self::assertStringContainsString("Gosub(call-feedback-q-$id-2,s,1)", $survey);
        self::assertStringContainsString('Playback(/offload/asterisk/sounds/other/cf-thanks)', $survey);
    }

    public function testQuestionsAcceptOnlyTheirScalesKeys(): void
    {
        $id = $this->active([['direction' => 'in', 'route_id' => 40]], ScalePresets::NPS);
        $conf = $this->builder()->contexts();

        preg_match("/\\[call-feedback-q-$id-1\\]\n(.*?)\n\n/s", $conf, $nps);
        self::assertStringContainsString('Set(TIMEOUT(digit)=2)', $nps[1], 'NPS waits for the 0 of a 10.');
        self::assertStringContainsString('exten => 10,1,Gosub(call-feedback-answer,s,1(1,10))', $nps[1]);
        self::assertStringContainsString('Background(/offload/asterisk/sounds/other/cf-q1)', $nps[1]);
        self::assertStringContainsString('GotoIf($[${CF_TRY} > 2]?give_up)', $nps[1]);

        preg_match("/\\[call-feedback-q-$id-2\\]\n(.*)/s", $conf, $yesNo);
        self::assertStringContainsString('exten => 2,1,Gosub(call-feedback-answer,s,1(2,0))', $yesNo[1], 'No is stored as 0.');
        self::assertStringNotContainsString('exten => 3,', $yesNo[1]);
    }

    public function testExclusionsBecomeDigitExtensions(): void
    {
        $this->active([['direction' => 'in', 'route_id' => 40]]);
        $this->editor->addExclusions(['5550000100', '+44 20 7946 0000'], '', Surveys::NOW);

        self::assertStringContainsString("[call-feedback-excluded]\nexten => 442079460000,1,Return()\nexten => 5550000100,1,Return()\n",
            $this->builder()->contexts());
    }

    public function testNothingAdminOrCallerTypedReachesTheDialplan(): void
    {
        $id = Surveys::complete($this->editor, $this->module, [['direction' => 'in', 'route_id' => 40]],
            name: 'Survey ${SHELL(id)} [evil] exten => s,1,Dial(PJSIP/9001)');
        $this->editor->activate($id, [Surveys::class, 'promptUsable'], Surveys::NOW);
        $conf = DialplanBuilder::fromDatabases($this->module, $this->config, fn (int $id): ?string =>
            $id === 12 ? '/tmp/x,1);System(rm' : Surveys::promptPath($id))->contexts();

        self::assertStringNotContainsString('SHELL', $conf);
        self::assertStringNotContainsString('evil', $conf);
        self::assertStringNotContainsString('System', $conf, 'A prompt path outside the pattern is not used.');
        self::assertStringNotContainsString('Dial(', $conf, 'The survey never places a call.');
        self::assertStringNotContainsString('include =>', $conf);
    }
}
