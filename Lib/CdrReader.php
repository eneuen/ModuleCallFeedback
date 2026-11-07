<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;

/**
 * Reads the core's call history (cdr_general) and provider list over
 * read-only connections. The module never writes to either database.
 *
 * Based on the Call Insights module's reader: the file is opened with
 * SQLITE_OPEN_READONLY and PRAGMA query_only, so even a bug cannot write to
 * it, and every query is short (on NFS/SMB storage, without WAL, a reader
 * blocks the core's writes).
 *
 * Plain PDO, no core dependencies, so it is tested without a PBX.
 */
final class CdrReader
{
    /** Milliseconds a read waits when the core is writing. */
    private const int BUSY_TIMEOUT_MS = 2000;

    private PDO $cdr;

    public function __construct(string $cdrDatabaseFile)
    {
        $this->cdr = self::openReadOnly($cdrDatabaseFile);
    }

    public static function openReadOnly(string $file): PDO
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException("Database file not found: $file");
        }
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Pdo\Sqlite constants exist since PHP 8.4; the PDO::SQLITE_* ones are deprecated in 8.5.
            Sqlite::ATTR_OPEN_FLAGS      => Sqlite::OPEN_READONLY,
        ]);
        $pdo->exec('PRAGMA query_only = 1');
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);

        return $pdo;
    }

    /**
     * Every leg of one call, through the linkedid index.
     *
     * @return list<array<string, mixed>>
     */
    public function legsOfCall(string $linkedId): array
    {
        $stmt = $this->cdr->prepare('SELECT * FROM cdr_general WHERE linkedid = ? ORDER BY id');
        $stmt->execute([$linkedId]);

        return $stmt->fetchAll();
    }

    /**
     * A value from the core's m_PbxSettings, or $default when it is not set
     * (the core then uses its built-in default).
     */
    public static function pbxSetting(string $configDatabaseFile, string $key, string $default): string
    {
        $stmt = self::openReadOnly($configDatabaseFile)->prepare('SELECT value FROM m_PbxSettings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? $default : (string)$value;
    }

    /**
     * Provider IDs (uniqid) from the core's configuration database, used to
     * tell inbound, outbound and internal calls apart.
     *
     * @return list<string>
     */
    public static function providerIds(string $configDatabaseFile): array
    {
        $pdo = self::openReadOnly($configDatabaseFile);

        return array_map('strval', $pdo->query('SELECT uniqid FROM m_Providers')->fetchAll(PDO::FETCH_COLUMN));
    }
}
