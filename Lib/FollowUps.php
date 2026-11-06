<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;
use PDO;

/**
 * Detractor follow-ups: a case for each session whose headline score is at
 * or below the detractor threshold, to be called back and closed.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class FollowUps
{
    public const string TABLE = 'm_FollowUps';
    public const array STATUSES = ['open', 'contacted', 'resolved'];
    public const int PAGE_SIZE = 50;
    public const int MAX_NOTE_LENGTH = 1000;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Opens a case when the session's headline answer makes it a detractor.
     *
     * @param array<string, mixed> $survey   SurveyRepository::find()
     * @param array<int, int>      $answers  Position => stored value.
     * @param array<string, mixed> $settings ModuleSettings::load()
     *
     * @return bool Whether a case was opened.
     */
    public function openIfDetractor(int $sessionId, array $survey, array $answers, array $settings, int $now): bool
    {
        $headline = $survey['questions'][1]['scale'] ?? null;
        if ($headline === null || !isset($answers[1]) || !Scores::isDetractor($headline, $answers[1], $settings)) {
            return false;
        }
        $stmt = $this->db->prepare('INSERT INTO ' . self::TABLE . " (session_id, status, created_at) VALUES (?, 'open', ?)
            ON CONFLICT (session_id) DO NOTHING");
        $stmt->execute([$sessionId, $now]);

        return $stmt->rowCount() === 1;
    }

    public function markAlerted(int $sessionId, int $now): void
    {
        $this->db->prepare('UPDATE ' . self::TABLE . ' SET alerted_at = ? WHERE session_id = ?')->execute([$now, $sessionId]);
    }

    /**
     * One page of cases, newest first, with their call and answers.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, counts: array<string, int>, avg_close_seconds: ?int}
     */
    public function list(string $status, int $page): array
    {
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown status.');
        }
        if ($page < 1 || $page > 10000) {
            throw new InvalidArgumentException('Unknown page.');
        }
        $where = $status === '' ? '' : 'WHERE f.status = :status';
        $params = $status === '' ? [] : [':status' => $status];

        $stmt = $this->db->prepare("SELECT f.id, f.status, f.note, f.created_at, f.updated_at, f.closed_at, f.closed_by,
                s.id AS session_id, s.survey_id, s.direction, s.did, s.caller_num, s.agent, s.queue, s.ended_at, s.linkedid,
                v.name AS survey_name, v.version AS survey_version, q.scale
            FROM " . self::TABLE . ' f
            JOIN m_SurveySessions s ON s.id = f.session_id
            JOIN m_Surveys v ON v.id = s.survey_id
            LEFT JOIN m_SurveyQuestions q ON q.survey_id = s.survey_id AND q.position = 1
            ' . $where . ' ORDER BY f.created_at DESC, f.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', self::PAGE_SIZE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * self::PAGE_SIZE, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $answers = $this->db->prepare('SELECT position, value FROM m_SurveyAnswers WHERE session_id = ? ORDER BY position');
        foreach ($rows as &$row) {
            $answers->execute([$row['session_id']]);
            $row['answers'] = array_map('intval', $answers->fetchAll(PDO::FETCH_KEY_PAIR));
            foreach (['id', 'session_id', 'survey_id', 'survey_version', 'created_at', 'ended_at'] as $field) {
                $row[$field] = (int)$row[$field];
            }
        }
        unset($row);

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->query('SELECT status, COUNT(*) FROM ' . self::TABLE . ' GROUP BY status')->fetchAll(PDO::FETCH_NUM) as [$s, $n]) {
            $counts[(string)$s] = (int)$n;
        }
        $avg = $this->db->query('SELECT AVG(closed_at - created_at) FROM ' . self::TABLE . " WHERE status = 'resolved' AND closed_at IS NOT NULL")
            ->fetchColumn();

        return [
            'rows'              => $rows,
            'total'             => $status === '' ? array_sum($counts) : $counts[$status],
            'counts'            => $counts,
            'avg_close_seconds' => $avg === null || $avg === false ? null : (int)round((float)$avg),
            'page_size'         => self::PAGE_SIZE,
        ];
    }

    /**
     * Changes a case's status and note. Resolving records when and by whom;
     * reopening clears that.
     *
     * @throws InvalidArgumentException
     */
    public function update(int $id, string $status, string $note, string $by, int $now): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown status.');
        }
        $note = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $note) ?? '');
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new InvalidArgumentException('The note can be up to ' . self::MAX_NOTE_LENGTH . ' characters.');
        }
        $by = mb_substr(preg_replace('/[^\p{L}\p{N}._@ -]/u', '', $by) ?? '', 0, 60);
        $resolved = $status === 'resolved';
        $stmt = $this->db->prepare('UPDATE ' . self::TABLE . ' SET status = ?, note = ?, updated_at = ?,
            closed_at = CASE WHEN ? THEN COALESCE(closed_at, ?) ELSE NULL END,
            closed_by = CASE WHEN ? THEN COALESCE(closed_by, ?) ELSE NULL END
            WHERE id = ?');
        $stmt->execute([$status, $note === '' ? null : $note, $now, $resolved ? 1 : 0, $now, $resolved ? 1 : 0, $by === '' ? null : $by, $id]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Unknown follow-up.');
        }
    }
}
