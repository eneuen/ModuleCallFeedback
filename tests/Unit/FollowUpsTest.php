<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallFeedback\Lib\AlertMessage;
use Modules\ModuleCallFeedback\Lib\FeedbackStore;
use Modules\ModuleCallFeedback\Lib\FollowUps;
use Modules\ModuleCallFeedback\Lib\ModuleSettings;
use Modules\ModuleCallFeedback\Lib\ScalePresets;
use Modules\ModuleCallFeedback\Lib\SurveyEditor;
use Modules\ModuleCallFeedback\Lib\SurveyRepository;
use Modules\ModuleCallFeedback\Tests\Unit\Support\Surveys;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class FollowUpsTest extends TestCase
{
    private TestDatabases $databases;
    private PDO $module;
    private FeedbackStore $store;
    private FollowUps $followUps;
    private array $survey;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $file = $this->databases->moduleDatabase();
        $this->module = TestDatabases::writable($file);
        $this->store = new FeedbackStore($file, false);
        $this->followUps = new FollowUps($this->module);
        $editor = new SurveyEditor($this->module, TestDatabases::writable($this->databases->configDatabase()));
        $id = Surveys::complete($editor, $this->module, [], headline: ScalePresets::NPS);
        $this->survey = (new SurveyRepository($this->module))->find($id);
    }

    /** Stores a session with an NPS answer and returns its ID. */
    private function session(string $linkedId, int $nps): int
    {
        $this->store->record(FeedbackStoreTest::record([
            'linkedid' => $linkedId, 'survey' => $this->survey['id'],
            'answers' => [1 => ['value' => $nps, 'at' => null]],
        ]), $this->survey);

        return (int)$this->store->session($linkedId, $this->survey['id'])['id'];
    }

    private function open(int $sessionId): bool
    {
        return $this->followUps->openIfDetractor($sessionId, $this->survey, $this->store->answersOf($sessionId),
            ModuleSettings::defaults(), Surveys::NOW);
    }

    public function testOnlyDetractorsOpenACaseAndOnlyOnce(): void
    {
        $detractor = $this->session('a', 6);
        $passive = $this->session('b', 7);

        self::assertTrue($this->open($detractor));
        self::assertFalse($this->open($detractor), 'A second attribution opens nothing new.');
        self::assertFalse($this->open($passive));

        $list = $this->followUps->list('open', 1);
        self::assertSame(1, $list['total']);
        self::assertSame([1 => 6], $list['rows'][0]['answers']);
        self::assertSame('5550000100', $list['rows'][0]['caller_num']);
        self::assertSame(ScalePresets::NPS, $list['rows'][0]['scale']);
    }

    public function testResolvingRecordsWhenAndWhoAndReopeningClearsIt(): void
    {
        $this->open($this->session('a', 0));
        $id = $this->followUps->list('', 1)['rows'][0]['id'];

        $this->followUps->update($id, 'resolved', "Called back,\nsorted.", 'admin', Surveys::NOW + 3600);
        $row = $this->followUps->list('resolved', 1)['rows'][0];
        self::assertSame(["Called back,\nsorted.", Surveys::NOW + 3600, 'admin'], [$row['note'], (int)$row['closed_at'], $row['closed_by']]);
        self::assertSame(3600, $this->followUps->list('', 1)['avg_close_seconds']);

        $this->followUps->update($id, 'open', '', 'admin', Surveys::NOW + 7200);
        $row = $this->followUps->list('open', 1)['rows'][0];
        self::assertSame([null, null, null], [$row['note'], $row['closed_at'], $row['closed_by']]);
    }

    public function testRejectsUnknownStatusesAndLongNotes(): void
    {
        $this->open($this->session('a', 0));
        $id = $this->followUps->list('', 1)['rows'][0]['id'];

        foreach ([['closed', ''], ['open', str_repeat('x', 1001)]] as [$status, $note]) {
            try {
                $this->followUps->update($id, $status, $note, 'admin', Surveys::NOW);
                self::fail("Accepted $status");
            } catch (InvalidArgumentException) {
                self::assertSame('open', $this->followUps->list('', 1)['rows'][0]['status']);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->followUps->list('deleted', 1);
    }

    public function testTheAlertNeverCarriesTheCallersNumber(): void
    {
        $message = AlertMessage::build('en-en', "Support\r\nBcc: x@example.com", ScalePresets::NPS, 3, '301', Surveys::NOW);

        self::assertSame('Call Feedback: a caller gave a low score', $message['subject']);
        self::assertStringContainsString('Survey: Support Bcc: x@example.com', $message['body'], 'Line breaks are removed.');
        self::assertStringContainsString('Score: 3 (0–10)', $message['body']);
        self::assertStringContainsString('Agent: 301', $message['body']);
        self::assertStringContainsString(AlertMessage::PAGE_PATH, $message['body']);
        self::assertStringNotContainsString('5550000100', $message['body']);
        self::assertStringStartsWith('Оценка звонков', AlertMessage::build('ru-ru', 'Опрос', ScalePresets::CSAT, 1, null, Surveys::NOW)['subject']);
    }
}
