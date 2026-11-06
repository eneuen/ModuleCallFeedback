<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;
use PDO;

/**
 * Module-wide settings, stored as name/value pairs in m_Settings. Every
 * setting has a default and a range, and save() validates all of them.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class ModuleSettings
{
    public const string TABLE = 'm_Settings';

    /** Name => [default, min, max] for integers. */
    private const array INTEGERS = [
        // A headline score at or below these opens a follow-up.
        'csat_detractor_max' => [2, 1, 4],
        'nps_detractor_max'  => [6, 0, 9],
        'ces_detractor_max'  => [2, 1, 4],
        // A group needs this many responses before its score is shown.
        'min_sample'         => [20, 1, 1000],
        // Surveys running at once, so they can't use up trunk channels.
        'max_concurrent'     => [20, 1, 500],
    ];

    /** Up to this many alert recipients. */
    public const int MAX_RECIPIENTS = 10;

    /** @return array<string, int|list<string>> */
    public static function defaults(): array
    {
        $defaults = ['alert_emails' => []];
        foreach (self::INTEGERS as $name => [$default]) {
            $defaults[$name] = $default;
        }

        return $defaults;
    }

    /** @return array<string, int|list<string>> */
    public static function load(PDO $db): array
    {
        $stored = $db->query('SELECT name, value FROM ' . self::TABLE)->fetchAll(PDO::FETCH_KEY_PAIR);
        $settings = self::defaults();
        foreach (self::INTEGERS as $name => [$default, $min, $max]) {
            if (isset($stored[$name]) && preg_match('/^-?[0-9]{1,6}$/', (string)$stored[$name])) {
                $settings[$name] = max($min, min($max, (int)$stored[$name]));
            }
        }
        if (isset($stored['alert_emails']) && (string)$stored['alert_emails'] !== '') {
            $settings['alert_emails'] = array_values(array_filter(
                explode(',', (string)$stored['alert_emails']),
                fn (string $e): bool => self::isEmail($e)
            ));
        }

        return $settings;
    }

    /**
     * Validates and stores every setting given; unknown names are refused.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, int|list<string>> The settings after saving.
     *
     * @throws InvalidArgumentException
     */
    public static function save(PDO $db, array $input): array
    {
        $values = [];
        foreach ($input as $name => $value) {
            if ($name === 'alert_emails') {
                $values[$name] = implode(',', self::parseEmails($value));
                continue;
            }
            if (!isset(self::INTEGERS[$name])) {
                throw new InvalidArgumentException("Unknown setting: $name");
            }
            [, $min, $max] = self::INTEGERS[$name];
            if (!is_scalar($value) || !preg_match('/^[0-9]{1,6}$/', (string)$value)
                || (int)$value < $min || (int)$value > $max) {
                throw new InvalidArgumentException("$name must be a whole number from $min to $max.");
            }
            $values[$name] = (string)(int)$value;
        }

        $stmt = $db->prepare('INSERT INTO ' . self::TABLE . ' (name, value) VALUES (?, ?)
            ON CONFLICT (name) DO UPDATE SET value = excluded.value');
        foreach ($values as $name => $value) {
            $stmt->execute([$name, $value]);
        }

        return self::load($db);
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function parseEmails(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('alert_emails must be a list of addresses.');
        }
        $emails = [];
        foreach ($value as $email) {
            $email = trim((string)$email);
            if (!self::isEmail($email)) {
                throw new InvalidArgumentException('Not a valid email address: ' . mb_substr($email, 0, 80));
            }
            $emails[strtolower($email)] = $email;
        }
        if (count($emails) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException('Up to ' . self::MAX_RECIPIENTS . ' alert recipients.');
        }

        return array_values($emails);
    }

    /** A plain address: no display names, no line breaks, nothing a mail header could be built from. */
    private static function isEmail(string $email): bool
    {
        return strlen($email) <= 254
            && preg_match('/^[^\s,;<>"\'\\\\]+@[^\s,;<>"\'\\\\]+$/', $email) === 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
