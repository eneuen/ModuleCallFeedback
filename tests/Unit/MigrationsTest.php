<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit;

use Modules\ModuleCallFeedback\Lib\Migrations;
use Modules\ModuleCallFeedback\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationsTest extends TestCase
{
    private TestDatabases $databases;
    private PDO $module;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $this->module = TestDatabases::writable($this->databases->moduleDatabase());
    }

    private function version(): ?string
    {
        $value = $this->module->query("SELECT value FROM m_Settings WHERE name = 'schema_version'")->fetchColumn();

        return $value === false ? null : (string)$value;
    }

    public function testANewInstallIsRecordedAtTheLatestVersion(): void
    {
        self::assertSame(Migrations::LATEST, Migrations::run($this->module, true));
        self::assertSame((string)Migrations::LATEST, $this->version());
    }

    public function testAnUpdateRunsEachLaterStepOnceInOrder(): void
    {
        $ran = [];
        $steps = [
            2 => function (PDO $db) use (&$ran): void { $ran[] = 2; $db->exec('CREATE TABLE m_Two (id INTEGER)'); },
            3 => function () use (&$ran): void { $ran[] = 3; },
        ];

        // A database from the first release, from before versions were recorded.
        self::assertSame(3, Migrations::run($this->module, false, $steps, 3));
        self::assertSame([2, 3], $ran);
        self::assertSame('3', $this->version());

        self::assertSame(3, Migrations::run($this->module, false, $steps, 3));
        self::assertSame([2, 3], $ran, 'Nothing runs twice.');
    }

    public function testAFailedStepLeavesTheDatabaseAtThePreviousVersion(): void
    {
        $steps = [
            2 => fn (PDO $db) => $db->exec('CREATE TABLE m_Two (id INTEGER)'),
            3 => function (PDO $db): void {
                $db->exec('CREATE TABLE m_Three (id INTEGER)');
                throw new RuntimeException('step 3 failed');
            },
        ];
        try {
            Migrations::run($this->module, false, $steps, 3);
            self::fail('The failure was swallowed.');
        } catch (RuntimeException) {
            self::assertSame('2', $this->version());
            $tables = $this->module->query("SELECT name FROM sqlite_master WHERE name IN ('m_Two', 'm_Three')")->fetchAll(PDO::FETCH_COLUMN);
            self::assertSame(['m_Two'], $tables, 'Step 3 was rolled back.');
        }
    }

    public function testAFreshInstallSkipsEveryStep(): void
    {
        $ran = false;
        Migrations::run($this->module, true, [2 => function () use (&$ran): void { $ran = true; }], 2);

        self::assertFalse($ran, 'New tables are already current.');
        self::assertSame('2', $this->version());
    }
}
