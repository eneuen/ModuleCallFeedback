<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallFeedback\Lib\CallBacks;
use Modules\ModuleCallFeedback\Lib\FeedbackStore;
use Modules\ModuleCallFeedback\Lib\FollowUps;
use Modules\ModuleCallFeedback\Lib\ModuleSettings;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use Modules\ModuleCallFeedback\Lib\SurveyRepository;
use Modules\ModuleCallFeedback\Tests\Unit\Support\Surveys;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CallBacksTest extends TestCase
{
    private const array EXTENSIONS = ['301', '302'];

    private TestDatabases $databases;
    private PDO $module;
    private CallBacks $callBacks;
    private FeedbackStore $store;
    private array $survey;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $file = $this->databases->moduleDatabase();
        $this->module = TestDatabases::writable($file);
        $this->store = new FeedbackStore($file, false);
        $this->callBacks = new CallBacks($this->module);
        $editor = new SurveyEditor($this->module, TestDatabases::writable($this->databases->configDatabase()));
        $id = Surveys::complete($editor, $this->module, [], headline: ScalePresets::NPS);
        $this->survey = (new SurveyRepository($this->module))->find($id);
    }

    /** A detractor case for a caller number; returns the follow-up ID. */
    private function followUp(string $caller): int
    {
        $linkedId = 'call-' . random_int(1, PHP_INT_MAX);
        $this->store->record(FeedbackStoreTest::record(['linkedid' => $linkedId, 'survey' => $this->survey['id'], 'caller' => $caller,
            'answers' => [1 => ['value' => 0, 'at' => null]]]), $this->survey);
        $session = (int)$this->store->session($linkedId, $this->survey['id'])['id'];
        (new FollowUps($this->module))->openIfDetractor($session, $this->survey, $this->store->answersOf($session), ModuleSettings::defaults(), Surveys::NOW);

        return (int)$this->module->query("SELECT id FROM m_FollowUps WHERE session_id = $session")->fetchColumn();
    }

    /** @return iterable<string, array{?string, list<string>, string}> */
    public static function modes(): iterable
    {
        yield 'listed prefix' => ['74951234567', ['7495'], CallBacks::ONE_CLICK];
        yield 'other prefix' => ['78001234567', ['7495'], CallBacks::TYPE_IN];
        yield 'no prefixes set' => ['74951234567', [], CallBacks::TYPE_IN];
        yield 'plus is never one click' => ['+74951234567', ['7495'], CallBacks::TYPE_IN];
        yield '00 is never one click' => ['0074951234567', ['00', '0074'], CallBacks::TYPE_IN];
        yield 'withheld' => [null, ['7495'], CallBacks::NONE];
        yield 'empty' => ['', [], CallBacks::NONE];
    }

    #[DataProvider('modes')]
    public function testHowANumberMayBeCalledBack(?string $number, array $prefixes, string $mode): void
    {
        self::assertSame($mode, CallBacks::mode($number, $prefixes));
    }

    public function testAListedNumberIsLoggedAndTheCaseMarkedContacted(): void
    {
        $case = $this->followUp('74951234567');

        $id = $this->callBacks->request($case, '301', '', ['7495'], self::EXTENSIONS, 'admin', Surveys::NOW);

        self::assertSame(['number' => '74951234567', 'extension' => '301'], $this->callBacks->find($id));
        self::assertSame('contacted', (new FollowUps($this->module))->list('', 1)['rows'][0]['status']);
        self::assertSame('admin', $this->callBacks->ofFollowUps([$case])[$case][0]['requested_by']);
    }

    public function testAnyOtherNumberMustBeTypedIn(): void
    {
        $case = $this->followUp('+447700900123');

        try {
            $this->callBacks->request($case, '301', '', ['7495'], self::EXTENSIONS, 'admin', Surveys::NOW);
            self::fail('Called an unlisted number without typing it.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('cf_CallBackTypeNumber', $e->getMessage());
        }
        try {
            $this->callBacks->request($case, '301', '+44 7700 900999', ['7495'], self::EXTENSIONS, 'admin', Surveys::NOW);
            self::fail('Accepted a different typed number.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('cf_CallBackTypeNumber', $e->getMessage());
        }

        $id = $this->callBacks->request($case, '301', '+44 7700 900123', ['7495'], self::EXTENSIONS, 'admin', Surveys::NOW);
        self::assertSame('447700900123', $this->callBacks->find($id)['number']);
    }

    public function testRefusesUnknownExtensionsRepeatsAndMissingNumbers(): void
    {
        $case = $this->followUp('74951234567');
        $this->callBacks->request($case, '301', '', ['7495'], self::EXTENSIONS, 'admin', Surveys::NOW);

        $attempts = [
            [$case, '9999', 'Unknown extension.', Surveys::NOW + 60],
            [$case, '301', 'cf_CallBackTooSoon', Surveys::NOW + 5],
            [$this->followUp(''), '301', 'cf_CallBackNoNumber', Surveys::NOW],
            [999, '301', 'Unknown follow-up.', Surveys::NOW],
        ];
        foreach ($attempts as [$id, $extension, $expected, $when]) {
            try {
                $this->callBacks->request($id, $extension, '', ['7495'], self::EXTENSIONS, 'admin', $when);
                self::fail("Accepted: $expected");
            } catch (InvalidArgumentException $e) {
                self::assertSame($expected, $e->getMessage());
            }
        }
    }

    public function testPrefixesAreDigitsAndNeverInternational(): void
    {
        self::assertSame(['7495', '8495'], ModuleSettings::parsePrefixes("7495, 8495\n7495"));
        foreach (['00', '+7', '7-495'] as $bad) {
            try {
                ModuleSettings::parsePrefixes($bad);
                self::fail("Accepted $bad");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
