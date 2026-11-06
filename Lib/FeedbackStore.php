<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use PDO;

/**
 * Writes survey results to the module's own database (db/module.db).
 *
 * The core creates the tables from Models/*.php at install; this class only
 * reads and writes rows. Plain PDO, no core dependencies, so it is tested
 * against a real-schema SQLite file without a PBX.
 */
final class FeedbackStore
{
    public const string SESSIONS = 'm_SurveySessions';
    public const string ANSWERS = 'm_SurveyAnswers';
    public const string SKIPS = 'm_SkipCounts';

    public const string RECORDED = 'recorded';
    public const string DUPLICATE = 'duplicate';
    public const string SKIP_COUNTED = 'skip_counted';
    public const string UNKNOWN_SURVEY = 'unknown_survey';

    private const int BUSY_TIMEOUT_MS = 5000;

    private PDO $db;

    public function __construct(string $moduleDatabaseFile, bool $useWal)
    {
        $this->db = new PDO('sqlite:' . $moduleDatabaseFile, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        if ($useWal) {
            $this->db->exec('PRAGMA journal_mode = WAL');
        }
    }

    /**
     * Stores one validated end-of-call record (Handoff::validate()).
     *
     * A skipped call adds one to its day/route/reason counter. Any other call
     * becomes a session with its answers. Answers outside the range of the
     * survey's question are dropped, and the status is worked out from the
     * answers that remain, so the stored status always matches the stored
     * answers. A second record for the same call and survey is ignored.
     *
     * @param array<string, mixed> $record
     * @param array{id: int, min_talk_seconds: int, questions: array<int, array{metric: string, min: int, max: int}>}|null $survey
     *
     * @return string One of the RECORDED, DUPLICATE, SKIP_COUNTED, UNKNOWN_SURVEY constants.
     */
    public function record(array $record, ?array $survey): string
    {
        if ($survey === null) {
            return self::UNKNOWN_SURVEY;
        }
        if ($record['status'] === 'skipped') {
            $this->countSkip($record);
            return self::SKIP_COUNTED;
        }

        $answers = [];
        foreach ($record['answers'] as $position => $answer) {
            $question = $survey['questions'][$position] ?? null;
            if ($question !== null && $answer['value'] >= $question['min'] && $answer['value'] <= $question['max']) {
                $answers[$position] = $answer;
            }
        }

        return $this->transaction(function () use ($record, $survey, $answers): string {
            $stmt = $this->db->prepare(
                'INSERT INTO ' . self::SESSIONS . ' (linkedid, survey_id, direction, status, context_id, route_id, did,
                    caller_num, answer_at, talk_seconds, offered_at, ended_at, excluded)
                 VALUES (:linkedid, :survey, :direction, :status, :context, :route, :did,
                    :caller, :answer_at, :talk, :offered_at, :ended_at, 0)
                 ON CONFLICT (linkedid, survey_id) DO NOTHING'
            );
            $stmt->execute([
                ':linkedid'   => $record['linkedid'],
                ':survey'     => $survey['id'],
                ':direction'  => $record['direction'],
                ':status'     => self::status($answers, count($survey['questions'])),
                ':context'    => $record['context_id'] === '' ? null : $record['context_id'],
                ':route'      => $record['route_id'],
                ':did'        => $record['did'] === '' ? null : $record['did'],
                ':caller'     => $record['caller'] === '' ? null : $record['caller'],
                ':answer_at'  => $record['answer_at'],
                ':talk'       => $record['talk'],
                ':offered_at' => $record['offered_at'],
                ':ended_at'   => $record['ended_at'],
            ]);
            if ($stmt->rowCount() === 0) {
                return self::DUPLICATE;
            }
            $sessionId = (int)$this->db->lastInsertId();
            $insert = $this->db->prepare(
                'INSERT INTO ' . self::ANSWERS . ' (session_id, position, value, answered_at) VALUES (?, ?, ?, ?)'
            );
            foreach ($answers as $position => $answer) {
                $insert->execute([$sessionId, $position, $answer['value'], $answer['at']]);
            }

            return self::RECORDED;
        });
    }

    /**
     * Sessions still waiting for their CDR fields, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingAttribution(int $endedBefore, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, linkedid, survey_id, direction, ended_at FROM ' . self::SESSIONS . '
             WHERE attributed_at IS NULL AND ended_at <= ? ORDER BY ended_at, id LIMIT ?'
        );
        $stmt->bindValue(1, $endedBefore, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function attribute(int $sessionId, ?string $agent, ?string $queue, ?int $cdrTalkSeconds, bool $excluded, int $now): void
    {
        $stmt = $this->db->prepare(
            'UPDATE ' . self::SESSIONS . ' SET agent = ?, queue = ?, cdr_talk_seconds = ?, excluded = ?, attributed_at = ?
             WHERE id = ?'
        );
        $stmt->execute([$agent, $queue, $cdrTalkSeconds, $excluded ? 1 : 0, $now, $sessionId]);
    }

    /** Removes a session and its answers (a call that never appeared in the CDR). */
    public function discard(int $sessionId): void
    {
        $this->transaction(function () use ($sessionId): void {
            $this->db->prepare('DELETE FROM ' . self::ANSWERS . ' WHERE session_id = ?')->execute([$sessionId]);
            $this->db->prepare('DELETE FROM ' . self::SESSIONS . ' WHERE id = ?')->execute([$sessionId]);
        });
    }

    /** @return array<string, mixed>|null */
    public function session(string $linkedId, int $surveyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::SESSIONS . ' WHERE linkedid = ? AND survey_id = ?');
        $stmt->execute([$linkedId, $surveyId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<int, int> Position => value. */
    public function answersOf(int $sessionId): array
    {
        $stmt = $this->db->prepare('SELECT position, value FROM ' . self::ANSWERS . ' WHERE session_id = ? ORDER BY position');
        $stmt->execute([$sessionId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return list<array<string, mixed>> */
    public function skipCounts(): array
    {
        return $this->db->query('SELECT day, direction, route_key, reason, count FROM ' . self::SKIPS . ' ORDER BY id')
            ->fetchAll();
    }

    /** The route a record came through, as stored in m_SkipCounts.route_key. */
    public static function routeKey(array $record): string
    {
        return $record['direction'] === 'out'
            ? 'out:' . (int)$record['route_id']
            : 'in:' . $record['context_id'] . ':' . $record['did'];
    }

    /**
     * 'completed' when every question has an answer, 'started' when some do,
     * 'offered' when none do.
     *
     * @param array<int, mixed> $answers
     */
    private static function status(array $answers, int $questionCount): string
    {
        if ($answers === []) {
            return 'offered';
        }

        return count($answers) >= $questionCount ? 'completed' : 'started';
    }

    private function countSkip(array $record): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::SKIPS . ' (day, direction, route_key, reason, count) VALUES (?, ?, ?, ?, 1)
             ON CONFLICT (day, direction, route_key, reason) DO UPDATE SET count = count + 1'
        );
        $stmt->execute([
            date('Y-m-d', $record['ended_at']),
            $record['direction'],
            self::routeKey($record),
            $record['reason'],
        ]);
    }

    private function transaction(callable $work): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
