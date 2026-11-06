<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallFeedback\Lib\ModuleSettings;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleSettingsTest extends TestCase
{
    private TestDatabases $databases;
    private PDO $db;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $this->db = TestDatabases::writable($this->databases->moduleDatabase());
    }

    public function testDefaults(): void
    {
        self::assertSame(ModuleSettings::defaults(), ModuleSettings::load($this->db));
        self::assertSame(6, ModuleSettings::defaults()['nps_detractor_max']);
    }

    public function testSavesAndLoads(): void
    {
        $saved = ModuleSettings::save($this->db, [
            'nps_detractor_max' => '5', 'alert_emails' => "lead@example.com, Support@Example.com;\nlead@example.com",
        ]);

        self::assertSame(5, $saved['nps_detractor_max']);
        self::assertSame(['lead@example.com', 'Support@Example.com'], $saved['alert_emails']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'unknown setting' => [['debug' => '1']];
        yield 'out of range' => [['nps_detractor_max' => '10']];
        yield 'not a number' => [['min_sample' => 'twenty']];
        yield 'header injection' => [['alert_emails' => "a@example.com\r\nBcc: b@example.com"]];
        yield 'display name' => [['alert_emails' => 'Lead <lead@example.com>']];
        yield 'not an address' => [['alert_emails' => 'lead']];
        yield 'too many' => [['alert_emails' => implode(',', array_map(fn (int $i): string => "a$i@example.com", range(1, 11)))]];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalid')]
    public function testRejects(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        ModuleSettings::save($this->db, $input);
    }
}
