<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use Modules\ModuleCallFeedback\Lib\BuiltInPrompts;
use PHPUnit\Framework\TestCase;

final class BuiltInPromptsTest extends TestCase
{
    private string $moduleDir;

    protected function setUp(): void
    {
        $this->moduleDir = sys_get_temp_dir() . '/cf-prompts-' . bin2hex(random_bytes(4));
        mkdir("$this->moduleDir/Sounds/en-en", 0777, true);
        touch("$this->moduleDir/Sounds/en-en/q-csat.wav");
    }

    protected function tearDown(): void
    {
        unlink("$this->moduleDir/Sounds/en-en/q-csat.wav");
        rmdir("$this->moduleDir/Sounds/en-en");
        rmdir("$this->moduleDir/Sounds");
        rmdir($this->moduleDir);
    }

    public function testIdsAreNegativeAndUnique(): void
    {
        $ids = array_values(BuiltInPrompts::IDS);
        self::assertSame($ids, array_unique($ids));
        foreach ($ids as $id) {
            self::assertLessThan(0, $id, 'The PBX\'s sound-file IDs are positive, so built-ins never clash with them.');
        }
        self::assertCount(7, BuiltInPrompts::catalogue());
    }

    public function testOnlyCatalogueNamesArePlayed(): void
    {
        self::assertSame('modulecallfeedback-q-csat', BuiltInPrompts::playbackName(BuiltInPrompts::id('q-csat')));
        self::assertSame('modulecallfeedback-intro', BuiltInPrompts::playbackName(-1));
        self::assertNull(BuiltInPrompts::playbackName(-99));
        self::assertNull(BuiltInPrompts::playbackName(12), 'A sound-file ID is not a built-in prompt.');
        foreach (BuiltInPrompts::IDS as $id) {
            self::assertMatchesRegularExpression('/^modulecallfeedback-[a-z-]+$/', (string)BuiltInPrompts::playbackName($id));
        }
    }

    public function testPreviewsPreferTheConvertedWebmCopy(): void
    {
        $sounds = "$this->moduleDir/asterisk-sounds";
        mkdir("$sounds/ru-ru", 0777, true);
        touch("$sounds/ru-ru/modulecallfeedback-q-csat.webm");

        $csat = array_values(array_filter(BuiltInPrompts::catalogue($sounds), fn (array $p): bool => $p['key'] === 'q-csat'))[0];
        self::assertSame("$sounds/ru-ru/modulecallfeedback-q-csat.webm", $csat['preview']['ru-ru']);
        self::assertSame("$sounds/en-en/modulecallfeedback-q-csat.wav", $csat['preview']['en-en'], 'Not converted yet: the shipped WAV.');
        self::assertSame([], BuiltInPrompts::catalogue()[0]['preview']);

        unlink("$sounds/ru-ru/modulecallfeedback-q-csat.webm");
        rmdir("$sounds/ru-ru");
        rmdir($sounds);
    }

    public function testAvailableOnlyWhenTheRecordingShipsInThatLanguage(): void
    {
        $csat = BuiltInPrompts::id('q-csat');

        self::assertTrue(BuiltInPrompts::available($this->moduleDir, $csat, 'en-en'));
        self::assertFalse(BuiltInPrompts::available($this->moduleDir, $csat, 'ru-ru'), 'No Russian recording in this folder.');
        self::assertFalse(BuiltInPrompts::available($this->moduleDir, BuiltInPrompts::id('intro'), 'en-en'));
        self::assertFalse(BuiltInPrompts::available($this->moduleDir, $csat, '../en-en'), 'Only the survey languages.');
        self::assertFalse(BuiltInPrompts::available($this->moduleDir, 12, 'en-en'));
    }
}
