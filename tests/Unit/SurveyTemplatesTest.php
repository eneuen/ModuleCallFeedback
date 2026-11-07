<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallFeedback\Lib\BuiltInPrompts;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use Modules\ModuleCallFeedback\Lib\SurveyRepository;
use Modules\ModuleCallFeedback\Lib\SurveyTemplates;
use Modules\ModuleCallFeedback\Tests\Unit\Support\Surveys;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SurveyTemplatesTest extends TestCase
{
    private TestDatabases $databases;
    private PDO $module;
    private SurveyEditor $editor;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $this->module = TestDatabases::writable($this->databases->moduleDatabase());
        $this->editor = new SurveyEditor($this->module, TestDatabases::writable($this->databases->configDatabase()));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function templates(): iterable
    {
        yield 'CSAT' => ['csat', ScalePresets::CSAT, 'q-csat'];
        yield 'NPS' => ['nps', ScalePresets::NPS, 'q-nps'];
        yield 'CES' => ['ces', ScalePresets::CES, 'q-ces'];
    }

    #[DataProvider('templates')]
    public function testATemplateIsADraftWithBuiltInPrompts(string $template, string $scale, string $prompt): void
    {
        $id = SurveyTemplates::create($this->editor, $template, 'Support line', 'ru-ru', Surveys::NOW);

        $survey = (new SurveyRepository($this->module))->find($id);
        self::assertSame(['Support line', 'draft', 'ru-ru'], [$survey['name'], $survey['status'], $survey['language']]);
        self::assertSame([BuiltInPrompts::id('intro'), BuiltInPrompts::id('invalid'), BuiltInPrompts::id('thanks')],
            [$survey['intro_prompt_id'], $survey['invalid_prompt_id'], $survey['thanks_prompt_id']]);
        $questions = array_values($survey['questions']);
        self::assertSame([$scale, ScalePresets::YES_NO], array_column($questions, 'scale'));
        self::assertSame([BuiltInPrompts::id($prompt), BuiltInPrompts::id('q-resolved')], array_column($questions, 'prompt_id'));
        self::assertSame([], (new SurveyRepository($this->module))->targetsOfFamily($id));
        self::assertEqualsCanonicalizing(['cf_ProblemNoTestCall', 'cf_ProblemNoTargets'],
            $this->editor->activationProblems($id, [Surveys::class, 'promptUsable']), 'Only routes and a test call are left to do.');
    }

    public function testEveryTemplateIsOffered(): void
    {
        self::assertSame(['csat', 'nps', 'ces'], SurveyTemplates::keys());
    }

    public function testUnknownTemplatesAndBadNamesAreRefused(): void
    {
        foreach ([['blank', 'Support'], ['csat', '']] as [$template, $name]) {
            try {
                SurveyTemplates::create($this->editor, $template, $name, 'en-en', Surveys::NOW);
                self::fail("Accepted template '$template' named '$name'");
            } catch (InvalidArgumentException) {
                self::assertSame(0, (int)$this->module->query('SELECT COUNT(*) FROM m_Surveys')->fetchColumn());
            }
        }
    }
}
