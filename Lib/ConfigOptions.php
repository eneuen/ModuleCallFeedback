<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use PDO;
use Throwable;

/**
 * What the survey editor offers, read from the core's configuration database:
 * the routes a survey can run on, the sound files it can play, and the
 * extensions a test call can ring.
 *
 * These names are entered by admins; the page renders them as text only.
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class ConfigOptions
{
    /**
     * @return array{in: list<array<string, mixed>>, out: list<array<string, mixed>>}
     */
    public static function routes(PDO $config, SurveyEditor $editor): array
    {
        $providers = self::providerNames($config);
        $routes = $editor->routes();
        $in = [];
        foreach ($routes['in'] as $id => $r) {
            $in[] = [
                'id'       => $id,
                'name'     => $r['name'],
                'number'   => $r['number'],
                'provider' => $r['provider'] === '' ? '' : ($providers[$r['provider']] ?? $r['provider']),
                // Numbers with patterns (X, ., [...]) can't be matched when the call ends.
                'usable'   => $r['number'] === '' || preg_match('/^[0-9]{1,32}$/', $r['number']) === 1,
            ];
        }
        $out = [];
        foreach ($routes['out'] as $id => $r) {
            $out[] = [
                'id'       => $id,
                'name'     => $r['name'],
                'prefix'   => $r['prefix'],
                'provider' => $providers[$r['provider']] ?? $r['provider'],
                'usable'   => true,
            ];
        }

        return ['in' => $in, 'out' => $out];
    }

    /**
     * Custom sound files (uploaded or made in Phrase Studio): ID and name.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function soundFiles(PDO $config): array
    {
        $rows = $config->query("SELECT id, name, path FROM m_SoundFiles WHERE category = 'custom' ORDER BY name, id")
            ->fetchAll(PDO::FETCH_ASSOC);

        // The stored path lets the editor play a file through the core's sound-files:playback endpoint,
        // which serves only the PBX's sound folders and only to a signed-in admin.
        return array_map(fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'path' => (string)$r['path']], $rows);
    }

    /**
     * Extensions with a phone, for the test call.
     *
     * @return list<array{number: string, name: string}>
     */
    public static function extensions(PDO $config): array
    {
        $rows = $config->query("SELECT number, callerid FROM m_Extensions WHERE type = 'SIP' ORDER BY number")
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(fn (array $r): array => ['number' => (string)$r['number'], 'name' => (string)$r['callerid']],
            array_filter($rows, fn (array $r): bool => preg_match('/^[0-9]{2,8}$/', (string)$r['number']) === 1)));
    }

    /**
     * Display names for the results pages: extensions and queues by number,
     * lines (DIDs) by their incoming route's name, providers by ID.
     *
     * @return array{extensions: array<string, string>, dids: array<string, string>, providers: array<string, string>}
     */
    public static function labels(PDO $config): array
    {
        $extensions = [];
        foreach ($config->query('SELECT number, callerid FROM m_Extensions')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string)$row['callerid'] !== '') {
                $extensions[(string)$row['number']] = (string)$row['callerid'];
            }
        }
        $dids = [];
        foreach ($config->query("SELECT number, rulename FROM m_IncomingRoutingTable WHERE number != '' ORDER BY priority DESC")
            ->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string)$row['rulename'] !== '') {
                $dids[(string)$row['number']] = (string)$row['rulename'];
            }
        }

        return ['extensions' => $extensions, 'dids' => $dids, 'providers' => self::providerNames($config)];
    }

    /** @return array<string, string> Provider uniqid => description (or host). */
    private static function providerNames(PDO $config): array
    {
        $names = [];
        foreach (['m_Sip', 'm_Iax'] as $table) {
            try {
                $rows = $config->query("SELECT uniqid, description, host FROM $table")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                continue;     // a table missing in a minimal database
            }
            foreach ($rows as $row) {
                $name = trim((string)$row['description']) !== '' ? (string)$row['description'] : (string)$row['host'];
                if ($name !== '') {
                    $names[(string)$row['uniqid']] = $name;
                }
            }
        }

        return $names;
    }
}
