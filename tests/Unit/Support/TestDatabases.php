<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Tests\Unit\Support;

use PDO;

/**
 * Builds throwaway SQLite files that match production:
 * - a CDR database with the real cdr_general schema (tests/fixtures/cdr_general.sql),
 *   filled with recorded fixture legs;
 * - a module database with the tables the core would create from Models/*.php.
 *
 * The module tables are derived from the model annotations, the same source
 * the core's installer uses, so the tests cannot drift from the real schema.
 */
final class TestDatabases
{
    private const string FIXTURES = __DIR__ . '/../../fixtures';
    private const string MODELS = __DIR__ . '/../../../Models';

    /** @var list<string> */
    private array $files = [];

    public function __destruct()
    {
        foreach ($this->files as $file) {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                @unlink($file . $suffix);
            }
        }
    }

    public function tempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'cf-test-') . '.db';
        $this->files[] = $file;

        return $file;
    }

    /**
     * @param list<array<string, mixed>> $legs
     */
    public function cdrDatabase(array $legs = []): string
    {
        $file = $this->tempFile();
        $pdo = self::writable($file);
        $pdo->exec((string)file_get_contents(self::FIXTURES . '/cdr_general.sql'));
        self::insertLegs($pdo, $legs);

        return $file;
    }

    /**
     * @param list<array<string, mixed>> $legs
     */
    public static function insertLegs(PDO $pdo, array $legs): void
    {
        foreach ($legs as $leg) {
            $columns = array_keys($leg);
            $stmt = $pdo->prepare(sprintf(
                'INSERT INTO cdr_general (%s) VALUES (%s)',
                implode(',', array_map(fn (string $c): string => "`$c`", $columns)),
                implode(',', array_fill(0, count($columns), '?'))
            ));
            $stmt->execute(array_values($leg));
        }
    }

    /**
     * A configuration database with the core tables the module reads: routes,
     * providers and installed modules. The routes match the CallSim layout.
     */
    public function configDatabase(): string
    {
        $file = $this->tempFile();
        $pdo = self::writable($file);
        $pdo->exec('CREATE TABLE m_IncomingRoutingTable (id INTEGER PRIMARY KEY, rulename VARCHAR, number VARCHAR,
            extension VARCHAR, provider VARCHAR, priority INTEGER, timeout INTEGER, action VARCHAR, note VARCHAR,
            audio_message_id VARCHAR)');
        $pdo->exec("INSERT INTO m_IncomingRoutingTable (id, rulename, number, extension, provider, priority, action) VALUES
            (1, 'default action', '', '201', '', 9999, 'extension'),
            (39, 'CallSim 5551000', '5551000', '3200', 'SIP-TRUNK-2EC28F63', 1001, ''),
            (40, 'CallSim 5551001', '5551001', '301', 'SIP-TRUNK-2EC28F63', 1002, ''),
            (41, 'Any number from the trunk', '', '301', 'SIP-TRUNK-2EC28F63', 1003, ''),
            (42, 'Any call', '', '301', '', 1004, '')");
        $pdo->exec('CREATE TABLE m_OutgoingRoutingTable (id INTEGER PRIMARY KEY, rulename VARCHAR, providerid VARCHAR,
            priority VARCHAR, numberbeginswith VARCHAR, restnumbers VARCHAR, trimfrombegin VARCHAR, prepend VARCHAR, note VARCHAR)');
        $pdo->exec("INSERT INTO m_OutgoingRoutingTable (id, rulename, providerid, priority, numberbeginswith, restnumbers)
            VALUES (35, 'CallSim outbound', 'SIP-TRUNK-2EC28F63', '1', '555', '7')");
        $pdo->exec('CREATE TABLE m_Providers (id INTEGER PRIMARY KEY, uniqid VARCHAR, type VARCHAR)');
        $pdo->exec("INSERT INTO m_Providers (uniqid, type) VALUES ('SIP-TRUNK-2EC28F63', 'SIP')");
        $pdo->exec('CREATE TABLE m_Sip (id INTEGER PRIMARY KEY, uniqid VARCHAR, description VARCHAR, host VARCHAR)');
        $pdo->exec("INSERT INTO m_Sip (uniqid, description, host) VALUES ('SIP-TRUNK-2EC28F63', 'CallSim carrier', '192.0.2.2')");
        $pdo->exec('CREATE TABLE m_SoundFiles (id INTEGER PRIMARY KEY, name VARCHAR, path VARCHAR, category VARCHAR)');
        $pdo->exec("INSERT INTO m_SoundFiles (id, name, path, category) VALUES
            (11, 'Survey intro', '/x/cf-intro.wav', 'custom'), (90, 'Hold music', '/x/moh.mp3', 'moh')");
        $pdo->exec('CREATE TABLE m_Extensions (id INTEGER PRIMARY KEY, number VARCHAR, type VARCHAR, callerid VARCHAR)');
        $pdo->exec("INSERT INTO m_Extensions (number, type, callerid) VALUES
            ('301', 'SIP', 'Anna'), ('3100', 'QUEUE', 'Sales queue'), ('302', 'SIP', '')");
        $pdo->exec("CREATE TABLE m_PbxExtensionModules (id INTEGER PRIMARY KEY, uniqid VARCHAR, disabled VARCHAR(1) NOT NULL)");
        $pdo->exec("INSERT INTO m_PbxExtensionModules (uniqid, disabled) VALUES ('ModuleCallFeedback', '0')");

        return $file;
    }

    public function moduleDatabase(): string
    {
        $file = $this->tempFile();
        $pdo = self::writable($file);
        foreach (glob(self::MODELS . '/*.php') as $model) {
            foreach (self::tableSql((string)file_get_contents($model)) as $sql) {
                $pdo->exec($sql);
            }
        }

        return $file;
    }

    public static function writable(string $file): PDO
    {
        return new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * @param array<string, mixed> $fixture
     *
     * @return list<array<string, mixed>>
     */
    public static function legsOf(string ...$scenarios): array
    {
        $legs = [];
        foreach ($scenarios as $scenario) {
            $fixture = json_decode(
                (string)file_get_contents(self::FIXTURES . "/cdr/$scenario.json"),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            array_push($legs, ...$fixture['legs']);
        }

        return $legs;
    }

    /** The provider ID every fixture was recorded with. */
    public static function fixtureProviderId(): string
    {
        $fixture = json_decode(
            (string)file_get_contents(self::FIXTURES . '/cdr/inbound_direct_answered.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        return $fixture['setup']['provider_id'];
    }

    /**
     * CREATE TABLE and CREATE INDEX statements for one model file, read from
     * its setSource(), @Column, @Primary/@Identity and @Indexes annotations.
     *
     * @return list<string>
     */
    private static function tableSql(string $source): array
    {
        preg_match("/setSource\\('([^']+)'\\)/", $source, $m);
        $table = $m[1];

        preg_match_all('/\/\*\*(.*?)\*\/\s*public \$(\w+);/s', $source, $props, PREG_SET_ORDER);
        $columns = [];
        foreach ($props as [, $doc, $name]) {
            if (!preg_match('/@Column\(type="(\w+)"/', $doc, $type)) {
                continue;
            }
            if (str_contains($doc, '@Identity')) {
                $columns[] = "`$name` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL";
                continue;
            }
            $sqlType = $type[1] === 'integer' ? 'INTEGER' : 'VARCHAR';
            $null = str_contains($doc, 'nullable=false') ? 'NOT NULL' : 'NULL';
            $default = preg_match('/default="([^"]*)"/', $doc, $d) ? " DEFAULT '{$d[1]}'" : '';
            $columns[] = "`$name` $sqlType $null$default";
        }
        $sql = ["CREATE TABLE `$table` (\n  " . implode(",\n  ", $columns) . "\n)"];

        preg_match_all("/\\[name='(\\w+)', columns=\\[([^\\]]+)\\], type='(\\w*)'\\]/", $source, $indexes, PREG_SET_ORDER);
        foreach ($indexes as [, $name, $cols, $type]) {
            $cols = implode(',', array_map(fn (string $c): string => '`' . trim($c, " '") . '`', explode(',', $cols)));
            $unique = strtoupper($type) === 'UNIQUE' ? 'UNIQUE ' : '';
            $sql[] = "CREATE {$unique}INDEX `i_{$table}_$name` ON `$table` ($cols)";
        }

        return $sql;
    }
}
