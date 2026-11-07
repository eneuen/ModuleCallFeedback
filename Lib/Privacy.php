<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;
use PDO;

/**
 * Personal data in the module database: callers' phone numbers.
 *
 * - Retention follows the PBX's call history (PBXRecordSavePeriod, days;
 *   under 30 means unlimited): older sessions lose the caller's number, and
 *   keep their scores, agent and queue so long-term trends survive.
 * - The frequency cap keeps one astdb key per number. Every key belongs to a
 *   stored session, so the worker finds the keys to delete from the numbers
 *   whose sessions have aged past the key's lifetime (keysToCheck()).
 * - "Delete all data for a number" removes that number's sessions, answers
 *   and follow-ups.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class Privacy
{
    /**
     * A phone number as typed by an admin, reduced to its digits: "+1 (555) 010-0100"
     * gives 15550100100. Only digits, spaces, a leading + and the usual separators
     * are accepted, so a typo such as "1e9" is refused rather than turned into
     * another number.
     */
    public static function digits(string $number): ?string
    {
        if (preg_match('/^\s*\+?[0-9][0-9 ().\-]*$/', $number) !== 1) {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $number) ?? '';

        return $digits === '' || strlen($digits) > 20 ? null : $digits;
    }

    /** Below this many days the PBX keeps its call history forever. */
    public const int UNLIMITED_BELOW_DAYS = 30;

    /** Internal bookkeeping in m_Settings, not a user setting. */
    private const string CAP_CHECKED_UNTIL = 'cap_keys_checked_until';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Clears the caller's number from sessions older than the retention period.
     *
     * @return int Sessions changed.
     */
    public function applyRetention(int $retentionDays, int $now): int
    {
        if ($retentionDays < self::UNLIMITED_BELOW_DAYS) {
            return 0;
        }
        $stmt = $this->db->prepare('UPDATE ' . FeedbackStore::SESSIONS . ' SET caller_num = NULL
            WHERE caller_num IS NOT NULL AND ended_at < ?');
        $stmt->execute([$now - $retentionDays * 86400]);

        return $stmt->rowCount();
    }

    /**
     * How long a frequency-cap key may live: the longest cap of any survey
     * still in use, but never longer than the retention period.
     */
    public function capKeyLifetimeDays(int $retentionDays): int
    {
        $longest = (int)$this->db->query("SELECT COALESCE(MAX(frequency_cap_days), 0) FROM m_Surveys WHERE status != 'archived'")
            ->fetchColumn();
        if ($retentionDays >= self::UNLIMITED_BELOW_DAYS) {
            $longest = min($longest, $retentionDays);
        }

        return $longest;
    }

    /**
     * Numbers whose cap key may have expired since the last check: the numbers
     * of sessions offered between the last check and the new cut-off. The
     * caller checks each key's stored time before deleting it, since the same
     * number may have been asked again since.
     *
     * @return array{cutoff: int, numbers: list<string>} Digits only.
     */
    public function keysToCheck(int $lifetimeDays, int $now): array
    {
        $cutoff = $now - max(1, $lifetimeDays) * 86400;
        $from = (int)($this->db->query("SELECT value FROM m_Settings WHERE name = '" . self::CAP_CHECKED_UNTIL . "'")->fetchColumn() ?: 0);
        $stmt = $this->db->prepare('SELECT DISTINCT caller_num FROM ' . FeedbackStore::SESSIONS . '
            WHERE caller_num IS NOT NULL AND offered_at IS NOT NULL AND offered_at >= ? AND offered_at < ?');
        $stmt->execute([$from, $cutoff]);
        $numbers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $number) {
            $digits = preg_replace('/[^0-9]/', '', (string)$number);
            if ($digits !== '') {
                $numbers[$digits] = true;
            }
        }

        return ['cutoff' => $cutoff, 'numbers' => array_map('strval', array_keys($numbers))];
    }

    /** Records that keys up to $cutoff were checked, so the next run starts there. */
    public function markKeysChecked(int $cutoff): void
    {
        $this->db->prepare('INSERT INTO m_Settings (name, value) VALUES (?, ?)
            ON CONFLICT (name) DO UPDATE SET value = excluded.value')->execute([self::CAP_CHECKED_UNTIL, (string)$cutoff]);
    }

    /**
     * Deletes every session of a number, with its answers and follow-ups.
     * Exclusions are kept: they are the admin's own list.
     *
     * @return array{digits: string, sessions: int}
     *
     * @throws InvalidArgumentException
     */
    public function forgetNumber(string $number): array
    {
        $digits = self::digits($number) ?? throw new InvalidArgumentException('Not a phone number: ' . mb_substr($number, 0, 40));
        $this->db->beginTransaction();
        try {
            $ids = $this->db->prepare('SELECT id FROM ' . FeedbackStore::SESSIONS . " WHERE REPLACE(caller_num, '+', '') = ?");
            $ids->execute([$digits]);
            $sessions = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
            foreach (array_chunk($sessions, 500) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $this->db->prepare('DELETE FROM ' . FeedbackStore::ANSWERS . " WHERE session_id IN ($marks)")->execute($chunk);
                $this->db->prepare('DELETE FROM ' . FollowUps::TABLE . " WHERE session_id IN ($marks)")->execute($chunk);
                $this->db->prepare('DELETE FROM ' . FeedbackStore::SESSIONS . " WHERE id IN ($marks)")->execute($chunk);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['digits' => $digits, 'sessions' => count($sessions)];
    }
}
