<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;
use PDO;

/**
 * Calling a detractor back from a follow-up, with the safeguards against
 * toll fraud: a caller can spoof an international or premium-rate number,
 * score 0 and wait for the PBX to call it.
 *
 * - One click only for numbers that start with a prefix the admin listed as
 *   safe (Settings; empty by default). A number starting with + or 00 is
 *   never one click.
 * - Any other number must be typed in, and must match the caller's number.
 * - The call rings the chosen extension first and then goes out through the
 *   PBX's normal outgoing routes and their restrictions.
 * - Every call-back is logged with who asked and when, and the same case
 *   can't be called back again within a short interval.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class CallBacks
{
    public const string TABLE = 'm_CallBacks';

    public const string ONE_CLICK = 'listed';
    public const string TYPE_IN = 'typed';
    public const string NONE = 'none';

    /** Seconds before the same case can be called back again. */
    public const int REPEAT_AFTER_SECONDS = 30;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * How a number may be called back: ONE_CLICK, TYPE_IN, or NONE when there
     * is no number (withheld, or cleared by retention).
     *
     * @param list<string> $safePrefixes Digit prefixes the admin trusts.
     */
    public static function mode(?string $number, array $safePrefixes): string
    {
        $digits = self::digits($number);
        if ($digits === '') {
            return self::NONE;
        }
        if (str_starts_with(trim((string)$number), '+') || str_starts_with($digits, '00')) {
            return self::TYPE_IN;
        }
        foreach ($safePrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($digits, $prefix)) {
                return self::ONE_CLICK;
            }
        }

        return self::TYPE_IN;
    }

    public static function digits(?string $number): string
    {
        return preg_replace('/[^0-9]/', '', (string)$number) ?? '';
    }

    /**
     * Checks a call-back request and logs it.
     *
     * @param list<string> $extensions Extensions that may be rung.
     *
     * @return int The log entry's ID, which the worker places the call from.
     *
     * @throws InvalidArgumentException With a message key the page translates.
     */
    public function request(int $followUpId, string $extension, string $typed, array $safePrefixes,
        array $extensions, string $by, int $now): int
    {
        $stmt = $this->db->prepare('SELECT s.caller_num FROM ' . FollowUps::TABLE . ' f
            JOIN ' . FeedbackStore::SESSIONS . ' s ON s.id = f.session_id WHERE f.id = ?');
        $stmt->execute([$followUpId]);
        $number = $stmt->fetchColumn();
        if ($number === false) {
            throw new InvalidArgumentException('Unknown follow-up.');
        }
        if (!in_array($extension, $extensions, true)) {
            throw new InvalidArgumentException('Unknown extension.');
        }
        $mode = self::mode($number === null ? null : (string)$number, $safePrefixes);
        if ($mode === self::NONE) {
            throw new InvalidArgumentException('cf_CallBackNoNumber');
        }
        if ($mode === self::TYPE_IN && self::digits($typed) !== self::digits((string)$number)) {
            throw new InvalidArgumentException('cf_CallBackTypeNumber');
        }
        $last = $this->db->prepare('SELECT MAX(requested_at) FROM ' . self::TABLE . ' WHERE followup_id = ?');
        $last->execute([$followUpId]);
        if ((int)$last->fetchColumn() > $now - self::REPEAT_AFTER_SECONDS) {
            throw new InvalidArgumentException('cf_CallBackTooSoon');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO ' . self::TABLE . ' (followup_id, number, extension, requested_by, requested_at, confirmation)
                VALUES (?, ?, ?, ?, ?, ?)')->execute([
                $followUpId, self::digits((string)$number), $extension, $by === '' ? null : mb_substr($by, 0, 60), $now, $mode,
            ]);
            $id = (int)$this->db->lastInsertId();
            $this->db->prepare('UPDATE ' . FollowUps::TABLE . " SET status = 'contacted', updated_at = ? WHERE id = ? AND status = 'open'")
                ->execute([$now, $followUpId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * A logged call-back, as the worker places it: the number and extension
     * come from the log, never from the request on the queue.
     *
     * @return array{number: string, extension: string}|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT number, extension FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['number' => (string)$row['number'], 'extension' => (string)$row['extension']];
    }

    /** @return array<int, list<array<string, mixed>>> Follow-up ID => its call-backs, newest first. */
    public function ofFollowUps(array $followUpIds): array
    {
        if ($followUpIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($followUpIds), '?'));
        $stmt = $this->db->prepare('SELECT followup_id, extension, requested_by, requested_at FROM ' . self::TABLE . "
            WHERE followup_id IN ($marks) ORDER BY requested_at DESC, id DESC");
        $stmt->execute(array_values($followUpIds));
        $byCase = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byCase[(int)$row['followup_id']][] = ['extension' => (string)$row['extension'],
                'requested_by' => $row['requested_by'], 'requested_at' => (int)$row['requested_at']];
        }

        return $byCase;
    }
}
