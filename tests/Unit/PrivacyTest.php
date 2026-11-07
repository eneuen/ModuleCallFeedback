<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallFeedback\Lib\FeedbackStore;
use Modules\ModuleCallFeedback\Lib\FollowUps;
use Modules\ModuleCallFeedback\Lib\ModuleSettings;
use Modules\ModuleCallFeedback\Lib\Privacy;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use Modules\ModuleCallFeedback\Lib\SurveyRepository;
use Modules\ModuleCallFeedback\Tests\Unit\Support\Surveys;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class PrivacyTest extends TestCase
{
    private const int DAY = 86400;

    private TestDatabases $databases;
    private PDO $module;
    private FeedbackStore $store;
    private Privacy $privacy;
    private SurveyEditor $editor;
    private array $survey;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $file = $this->databases->moduleDatabase();
        $this->module = TestDatabases::writable($file);
        $this->store = new FeedbackStore($file, false);
        $this->privacy = new Privacy($this->module);
        $this->editor = new SurveyEditor($this->module, TestDatabases::writable($this->databases->configDatabase()));
        $id = Surveys::complete($this->editor, $this->module, [], headline: ScalePresets::NPS);
        $this->survey = (new SurveyRepository($this->module))->find($id);
    }

    private function session(string $linkedId, string $caller, int $endedAt, int $nps = 9): int
    {
        $this->store->record(FeedbackStoreTest::record([
            'linkedid' => $linkedId, 'survey' => $this->survey['id'], 'caller' => $caller,
            'ended_at' => $endedAt, 'offered_at' => $endedAt - 20,
            'answers' => [1 => ['value' => $nps, 'at' => null]],
        ]), $this->survey);

        return (int)$this->store->session($linkedId, $this->survey['id'])['id'];
    }

    public function testRetentionClearsOldNumbersAndKeepsScores(): void
    {
        $now = Surveys::NOW;
        $old = $this->session('old', '5550000100', $now - 40 * self::DAY);
        $new = $this->session('new', '5550000100', $now - 10 * self::DAY);

        self::assertSame(0, $this->privacy->applyRetention(0, $now), 'Under 30 days means unlimited.');
        self::assertSame(1, $this->privacy->applyRetention(30, $now));

        self::assertNull($this->store->sessionById($old)['caller_num']);
        self::assertSame([1 => 9], $this->store->answersOf($old), 'The score stays.');
        self::assertSame('5550000100', $this->store->sessionById($new)['caller_num']);
    }

    public function testCapKeysLiveAsLongAsTheLongestCapButNotPastRetention(): void
    {
        $this->editor->update($this->survey['id'], ['frequency_cap_days' => '90']);

        self::assertSame(90, $this->privacy->capKeyLifetimeDays(0), 'Unlimited retention.');
        self::assertSame(90, $this->privacy->capKeyLifetimeDays(360));
        self::assertSame(30, $this->privacy->capKeyLifetimeDays(30), 'Privacy wins over the cap.');
    }

    public function testEachNumberIsCheckedOnceAsItsSessionsAgeOut(): void
    {
        $now = Surveys::NOW;
        $this->session('a', '+15550000100', $now - 40 * self::DAY);
        $this->session('b', '15550000100', $now - 35 * self::DAY);
        $this->session('c', '5550000200', $now - 5 * self::DAY);

        $first = $this->privacy->keysToCheck(30, $now);
        self::assertSame(['15550000100'], $first['numbers'], 'Digits only, once, and only past the lifetime.');
        $this->privacy->markKeysChecked($first['cutoff']);

        self::assertSame([], $this->privacy->keysToCheck(30, $now + 60)['numbers'], 'Already checked.');
        self::assertSame(['5550000200'], $this->privacy->keysToCheck(30, $now + 26 * self::DAY)['numbers']);
    }

    public function testForgettingANumberDeletesItsSessionsAnswersAndFollowUps(): void
    {
        $gone = $this->session('a', '+15550000100', Surveys::NOW, 2);
        $this->session('b', '15550000100', Surveys::NOW);
        $kept = $this->session('c', '5550000200', Surveys::NOW, 2);
        $followUps = new FollowUps($this->module);
        foreach ([$gone, $kept] as $id) {
            $followUps->openIfDetractor($id, $this->survey, $this->store->answersOf($id), ModuleSettings::defaults(), Surveys::NOW);
        }

        self::assertSame(['digits' => '15550000100', 'sessions' => 2], $this->privacy->forgetNumber('+1 (555) 000-0100'));

        self::assertNull($this->store->sessionById($gone));
        self::assertSame([], $this->store->answersOf($gone));
        self::assertSame(1, $followUps->list('', 1)['total']);
        self::assertNotNull($this->store->sessionById($kept));
        self::assertSame(1, (int)$this->module->query('SELECT COUNT(*) FROM ' . FeedbackStore::SESSIONS . ' WHERE is_test = 0')->fetchColumn());

        $this->expectException(InvalidArgumentException::class);
        $this->privacy->forgetNumber('anonymous');
    }

    public function testPhoneNumbersAreDigitsOrRefused(): void
    {
        self::assertSame('15550100100', Privacy::digits('+1 (555) 010-0100'));
        self::assertSame('5550000100', Privacy::digits(' 555.000.0100 '));
        foreach (['', '+', '1e9', '1 OR 1=1', '${SHELL(id)}', "555\n0100", str_repeat('1', 21)] as $bad) {
            self::assertNull(Privacy::digits($bad), "Accepted '$bad'");
        }
    }
}
