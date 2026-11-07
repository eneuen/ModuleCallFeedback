<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use Modules\ModuleCallFeedback\Lib\SurveyRepository;
use Modules\ModuleCallFeedback\Tests\Unit\Support\Surveys;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SurveyEditorTest extends TestCase
{
    private const array IN_DIRECT = ['direction' => 'in', 'route_id' => 40];
    private const array OUT_ROUTE = ['direction' => 'out', 'route_id' => 35];

    private TestDatabases $databases;
    private PDO $module;
    private PDO $config;
    private SurveyEditor $editor;
    private SurveyRepository $repository;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $this->module = TestDatabases::writable($this->databases->moduleDatabase());
        $this->config = TestDatabases::writable($this->databases->configDatabase());
        $this->editor = new SurveyEditor($this->module, $this->config);
        $this->repository = new SurveyRepository($this->module);
    }

    private function usable(): callable
    {
        return [Surveys::class, 'promptUsable'];
    }

    public function testANewSurveyIsADraftWithOneCsatQuestion(): void
    {
        $id = $this->editor->create('  Support line ', 'ru-ru', Surveys::NOW);

        $survey = $this->repository->find($id);
        self::assertSame(['Support line', 'draft', 'ru-ru', $id, 1], [$survey['name'], $survey['status'],
            $survey['language'], $survey['family_id'], $survey['version']]);
        self::assertSame([20, 100, 30], [$survey['min_talk_seconds'], $survey['sample_percent'], $survey['frequency_cap_days']]);
        self::assertSame(ScalePresets::CSAT, $survey['questions'][1]['scale']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidUpdates(): iterable
    {
        yield 'empty name' => [['name' => '   ']];
        yield 'long name' => [['name' => str_repeat('x', 61)]];
        yield 'unknown language' => [['language' => 'de-de']];
        yield 'unknown field' => [['status' => 'active']];
        yield 'prompt ID with text' => [['intro_prompt_id' => '12); System(id']];
        yield 'sampling of 0' => [['sample_percent' => '0']];
        yield 'negative talk time' => [['min_talk_seconds' => '-5']];
        yield 'no questions' => [['questions' => []]];
        yield 'four questions' => [['questions' => array_fill(0, 4, ['scale' => ScalePresets::CSAT])]];
        yield 'unknown scale' => [['questions' => [['scale' => 'stars10']]]];
        yield 'yes/no as headline' => [['questions' => [['scale' => ScalePresets::YES_NO]]]];
        yield 'timeout too long' => [['questions' => [['scale' => ScalePresets::NPS, 'timeout_seconds' => '99']]]];
        yield 'no days' => [['hours_days' => '0']];
        yield 'bad time' => [['hours_start' => '9am', 'hours_end' => '18:00']];
        yield 'start without end' => [['hours_start' => '09:00']];
        yield 'same start and end' => [['hours_start' => '09:00', 'hours_end' => '09:00']];
        yield 'time with dialplan' => [['hours_start' => '09:00,*,*,*?x)', 'hours_end' => '18:00']];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('invalidUpdates')]
    public function testRejectsInvalidUpdates(array $fields): void
    {
        $id = $this->editor->create('Survey', 'en-en', Surveys::NOW);

        $this->expectException(InvalidArgumentException::class);
        $this->editor->update($id, $fields);
    }

    public function testNamesLoseControlCharacters(): void
    {
        $id = $this->editor->create("Line\n[evil]\r\nexten", 'en-en', Surveys::NOW);

        self::assertSame('Line [evil]  exten', $this->repository->find($id)['name']);
    }

    public function testTargetsMustBeExistingRoutesOtherThanTheDefault(): void
    {
        $id = $this->editor->create('Survey', 'en-en', Surveys::NOW);
        $this->editor->setTargets($id, [self::IN_DIRECT, self::OUT_ROUTE, self::IN_DIRECT]);
        self::assertSame([self::IN_DIRECT, self::OUT_ROUTE], $this->repository->targetsOfFamily($id));

        foreach ([['direction' => 'in', 'route_id' => 1], ['direction' => 'in', 'route_id' => 999],
                  ['direction' => 'sideways', 'route_id' => 40], ['direction' => 'out', 'route_id' => '35 OR 1=1']] as $bad) {
            try {
                $this->editor->setTargets($id, [$bad]);
                self::fail('Accepted ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::assertCount(2, $this->repository->targetsOfFamily($id), 'A refused change leaves the targets as they were.');
            }
        }
    }

    public function testActivationNeedsPromptsATestCallAndTargets(): void
    {
        $id = $this->editor->create('Survey', 'en-en', Surveys::NOW);

        self::assertEqualsCanonicalizing(
            ['cf_ProblemMissingPrompt', 'cf_ProblemNoTestCall', 'cf_ProblemNoTargets'],
            $this->editor->activationProblems($id, $this->usable())
        );

        $complete = Surveys::complete($this->editor, $this->module, [self::IN_DIRECT], tested: false);
        self::assertSame(['cf_ProblemNoTestCall'], $this->editor->activationProblems($complete, $this->usable()));
        Surveys::testCall($this->module, $complete);
        self::assertSame([], $this->editor->activationProblems($complete, $this->usable()));
        self::assertSame(['cf_ProblemPromptMissingFile'], $this->editor->activationProblems($complete, fn (): bool => false));
    }

    public function testActivatingAVersionArchivesThePreviousOne(): void
    {
        $v1 = Surveys::complete($this->editor, $this->module, [self::IN_DIRECT]);
        $this->editor->activate($v1, $this->usable(), Surveys::NOW);

        $v2 = $this->editor->newVersion($v1, Surveys::NOW + 10);
        self::assertSame($v2, $this->editor->newVersion($v1, Surveys::NOW + 20), 'One draft per family.');
        $draft = $this->repository->find($v2);
        self::assertSame([2, $v1, 'draft'], [$draft['version'], $draft['family_id'], $draft['status']]);
        self::assertSame($this->repository->find($v1)['questions'], $draft['questions'], 'The questions are copied.');

        Surveys::testCall($this->module, $v2);
        $this->editor->activate($v2, $this->usable(), Surveys::NOW + 30);

        self::assertSame('archived', $this->repository->find($v1)['status']);
        self::assertSame('active', $this->repository->find($v2)['status']);
        self::assertSame([['survey_id' => $v2, 'direction' => 'in', 'route_id' => 40]], $this->repository->activeTargets());
    }

    public function testAnActiveSurveysQuestionsAreFixedButItsRulesCanChange(): void
    {
        $id = Surveys::complete($this->editor, $this->module, [self::IN_DIRECT]);
        $this->editor->activate($id, $this->usable(), Surveys::NOW);

        $this->editor->update($id, ['sample_percent' => '50', 'min_talk_seconds' => '30']);
        self::assertSame(50, $this->repository->find($id)['sample_percent']);

        $this->expectException(InvalidArgumentException::class);
        $this->editor->update($id, ['questions' => [['scale' => ScalePresets::NPS]]]);
    }

    public function testOnlyOneActiveSurveyPerRoute(): void
    {
        $first = Surveys::complete($this->editor, $this->module, [self::IN_DIRECT]);
        $this->editor->activate($first, $this->usable(), Surveys::NOW);
        $second = Surveys::complete($this->editor, $this->module, [self::IN_DIRECT, self::OUT_ROUTE], name: 'Other');

        self::assertSame(['cf_ProblemTargetTaken'], $this->editor->activationProblems($second, $this->usable()));

        $this->editor->setTargets($second, [self::OUT_ROUTE]);
        $this->editor->activate($second, $this->usable(), Surveys::NOW);

        $this->expectException(InvalidArgumentException::class);
        $this->editor->setTargets($second, [self::OUT_ROUTE, self::IN_DIRECT]);
    }

    public function testRefusesWhileTheDefaultActionPlaysASound(): void
    {
        $id = Surveys::complete($this->editor, $this->module, [self::IN_DIRECT]);
        $this->config->exec("UPDATE m_IncomingRoutingTable SET action = 'playback', extension = '' WHERE id = 1");

        self::assertSame(['cf_ProblemDefaultActionPlayback'], $this->editor->activationProblems($id, $this->usable()));

        // Outbound-only surveys don't depend on the inbound hook.
        $this->editor->setTargets($id, [self::OUT_ROUTE]);
        self::assertSame([], $this->editor->activationProblems($id, $this->usable()));
    }

    public function testRefusesWhileAnotherSurveyModuleIsEnabled(): void
    {
        $id = Surveys::complete($this->editor, $this->module, [self::OUT_ROUTE]);
        $this->config->exec("INSERT INTO m_PbxExtensionModules (uniqid, disabled) VALUES ('ModuleQualityAssessment', '0')");

        self::assertSame(['cf_ProblemOtherSurveyModule'], $this->editor->activationProblems($id, $this->usable()));

        $this->config->exec("UPDATE m_PbxExtensionModules SET disabled = '1' WHERE uniqid = 'ModuleQualityAssessment'");
        self::assertSame([], $this->editor->activationProblems($id, $this->usable()));
    }

    public function testArchivedSurveysStopRunning(): void
    {
        $id = Surveys::complete($this->editor, $this->module, [self::IN_DIRECT]);
        $this->editor->activate($id, $this->usable(), Surveys::NOW);
        $this->editor->archive($id, Surveys::NOW + 5);

        self::assertSame([], $this->repository->activeTargets());
        self::assertSame([], $this->repository->callable());
        $this->expectException(InvalidArgumentException::class);
        $this->editor->update($id, ['sample_percent' => '10']);
    }

    public function testDuplicateStartsANewFamilyWithoutTargets(): void
    {
        $id = Surveys::complete($this->editor, $this->module, [self::IN_DIRECT]);
        $copy = $this->editor->duplicate($id, Surveys::NOW);

        $survey = $this->repository->find($copy);
        self::assertSame([$copy, 1, 'After-call survey (copy)'], [$survey['family_id'], $survey['version'], $survey['name']]);
        self::assertSame([], $this->repository->targetsOfFamily($copy));
    }

    public function testExclusionsKeepDigitsOnly(): void
    {
        self::assertSame(2, $this->editor->addExclusions("+1 (555) 010-0100\n5550000100, 5550000100", 'Staff', Surveys::NOW));
        self::assertSame(['15550100100', '5550000100'], $this->repository->exclusions());

        $this->expectException(InvalidArgumentException::class);
        $this->editor->addExclusions(['no digits here'], '', Surveys::NOW);
    }
}
