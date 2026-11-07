<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use PDO;
use Throwable;

/**
 * Changes to the module database between versions.
 *
 * The core creates tables from the model annotations on install and adds new
 * columns on update, but it doesn't reliably change an existing table's
 * indexes or move data. Anything like that goes here as a numbered step. The
 * database records the step it has reached (SCHEMA_VERSION in m_Settings), so
 * an update runs each later step once, in order, in a transaction. A new
 * database starts at the latest version: its tables are already current.
 *
 * Version 1 is the first release's schema. To change the schema later, add
 * the step as the next number in steps() and raise LATEST, with a unit test
 * that upgrades a version-1 database.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class Migrations
{
    /** Bookkeeping in m_Settings, not a user setting. */
    public const string SCHEMA_VERSION = 'schema_version';

    public const int LATEST = 1;

    /**
     * Brings the database up to LATEST.
     *
     * @param bool $fresh True when the tables were just created (a new install).
     * @param array<int, callable(PDO): void>|null $steps For tests: replaces steps().
     *
     * @return int The version the database is at afterwards.
     */
    public static function run(PDO $db, bool $fresh, ?array $steps = null, int $latest = self::LATEST): int
    {
        $stmt = $db->prepare('SELECT value FROM ' . ModuleSettings::TABLE . ' WHERE name = ?');
        $stmt->execute([self::SCHEMA_VERSION]);
        $stored = $stmt->fetchColumn();
        // A database from before versions were recorded holds the first release's schema.
        $version = $fresh ? $latest : ($stored === false ? 1 : (int)$stored);

        foreach ($steps ?? self::steps() as $to => $step) {
            if ($to <= $version || $to > $latest) {
                continue;
            }
            $db->beginTransaction();
            try {
                $step($db);
                self::record($db, $to);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            $version = $to;
        }
        self::record($db, $version);

        return $version;
    }

    /**
     * The steps after version 1, keyed by the version each one brings the database to.
     *
     * @return array<int, callable(PDO): void>
     */
    private static function steps(): array
    {
        return [];
    }

    private static function record(PDO $db, int $version): void
    {
        $db->prepare('INSERT INTO ' . ModuleSettings::TABLE . ' (name, value) VALUES (?, ?)
            ON CONFLICT (name) DO UPDATE SET value = excluded.value')->execute([self::SCHEMA_VERSION, (string)$version]);
    }
}
