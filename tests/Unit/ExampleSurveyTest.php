<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use Modules\ModuleCallFeedback\Lib\BuiltInPrompts;
use Modules\ModuleCallFeedback\Lib\DialplanBuilder;
use Modules\ModuleCallFeedback\Lib\ExampleSurvey;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use Modules\ModuleCallFeedback\Lib\SurveyRepository;
use Modules\ModuleCallFeedback\Tests\Unit\Support\Surveys;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class ExampleSurveyTest extends TestCase
{
    private TestDatabases $databases;
    private PDO $module;
    private PDO $config;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $this->module = TestDatabases::writable($this->databases->moduleDatabase());
        $this->config = TestDatabases::writable($this->databases->configDatabase());
    }

    public function testANewInstallGetsADraftWithBuiltInPrompts(): void
    {
        $id = ExampleSurvey::createOnce($this->module, $this->config, 'en-en', Surveys::NOW);

        $survey = (new SurveyRepository($this->module))->find((int)$id);
        self::assertSame(['Example Survey', 'draft', 'en-en'], [$survey['name'], $survey['status'], $survey['language']]);
        self::assertSame([BuiltInPrompts::id('intro'), BuiltInPrompts::id('invalid'), BuiltInPrompts::id('thanks')],
            [$survey['intro_prompt_id'], $survey['invalid_prompt_id'], $survey['thanks_prompt_id']]);
        self::assertSame([ScalePresets::CSAT, ScalePresets::YES_NO], array_column(array_values($survey['questions']), 'scale'));
        self::assertSame([BuiltInPrompts::id('q-csat'), BuiltInPrompts::id('q-resolved')], array_column(array_values($survey['questions']), 'prompt_id'));
        self::assertSame([], (new SurveyRepository($this->module))->targetsOfFamily((int)$id), 'No routes: it reaches no caller.');
        self::assertSame(0, (int)$this->module->query('SELECT COUNT(*) FROM m_SurveySessions')->fetchColumn(), 'No sample results.');

        $editor = new SurveyEditor($this->module, $this->config);
        self::assertEqualsCanonicalizing(['cf_ProblemNoTestCall', 'cf_ProblemNoTargets'],
            $editor->activationProblems((int)$id, [Surveys::class, 'promptUsable']), 'Only routes and a test call are left to do.');
    }

    public function testTheExampleFollowsThePbxLanguage(): void
    {
        $id = ExampleSurvey::createOnce($this->module, $this->config, 'ru-ru', Surveys::NOW);
        $survey = (new SurveyRepository($this->module))->find((int)$id);

        self::assertSame(['Пример опроса', 'ru-ru'], [$survey['name'], $survey['language']]);

        $other = new TestDatabases();
        $fallback = ExampleSurvey::createOnce(TestDatabases::writable($other->moduleDatabase()), $this->config, 'de-de', Surveys::NOW);
        self::assertNotNull($fallback, 'A language without built-in prompts falls back to English.');
    }

    public function testItIsCreatedOnlyOnce(): void
    {
        $id = (int)ExampleSurvey::createOnce($this->module, $this->config, 'en-en', Surveys::NOW);
        self::assertNull(ExampleSurvey::createOnce($this->module, $this->config, 'en-en', Surveys::NOW + 10), 'An update keeps the database.');

        (new SurveyEditor($this->module, $this->config))->deleteDraft($id);
        self::assertNull(ExampleSurvey::createOnce($this->module, $this->config, 'en-en', Surveys::NOW + 20), 'A deleted example stays deleted.');
        self::assertSame(0, (int)$this->module->query('SELECT COUNT(*) FROM m_Surveys')->fetchColumn());
    }

    public function testAnInstallThatAlreadyHasSurveysGetsNone(): void
    {
        (new SurveyEditor($this->module, $this->config))->create('Ours', 'en-en', Surveys::NOW);

        self::assertNull(ExampleSurvey::createOnce($this->module, $this->config, 'en-en', Surveys::NOW));
        self::assertSame(['Ours'], $this->module->query('SELECT name FROM m_Surveys')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testTheDialplanPlaysBuiltInPromptsByName(): void
    {
        $id = (int)ExampleSurvey::createOnce($this->module, $this->config, 'ru-ru', Surveys::NOW);
        $editor = new SurveyEditor($this->module, $this->config);
        $editor->setTargets($id, [['direction' => 'in', 'route_id' => 40]]);
        Surveys::testCall($this->module, $id);
        $editor->activate($id, [Surveys::class, 'promptUsable'], Surveys::NOW);

        $conf = DialplanBuilder::fromDatabases($this->module, $this->config, fn (int $sound): ?string => self::fail('Built-ins never look up a sound file.'))
            ->contexts();

        self::assertStringContainsString('Set(CHANNEL(language)=ru-ru)', $conf, 'Asterisk plays the survey language\'s recordings.');
        self::assertStringContainsString('Playback(modulecallfeedback-intro)', $conf);
        self::assertStringContainsString('Background(modulecallfeedback-q-csat)', $conf);
        self::assertStringContainsString('Background(modulecallfeedback-q-resolved)', $conf);
        self::assertStringContainsString('Playback(modulecallfeedback-invalid)', $conf);
        self::assertStringContainsString('Playback(modulecallfeedback-thanks)', $conf);
    }
}
