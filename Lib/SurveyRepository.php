<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use PDO;

/**
 * Reads survey definitions from the module database: versions, questions,
 * targets and exclusions. Used by the dialplan, the worker and the pages.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class SurveyRepository
{
    public const string SURVEYS = 'm_Surveys';
    public const string QUESTIONS = 'm_SurveyQuestions';
    public const string TARGETS = 'm_SurveyTargets';
    public const string EXCLUSIONS = 'm_Exclusions';

    public const string DRAFT = 'draft';
    public const string ACTIVE = 'active';
    public const string ARCHIVED = 'archived';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * One survey version with its questions (position => question), or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $surveyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::SURVEYS . ' WHERE id = ?');
        $stmt->execute([$surveyId]);
        $survey = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($survey === false) {
            return null;
        }

        return $this->withQuestions([$survey])[0];
    }

    /**
     * Every version that can be called: active ones on their targets, drafts
     * from a test call. Archived versions get no dialplan.
     *
     * @return list<array<string, mixed>>
     */
    public function callable(): array
    {
        $rows = $this->db->query('SELECT * FROM ' . self::SURVEYS . " WHERE status IN ('draft', 'active') ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

        return $this->withQuestions($rows);
    }

    /**
     * The routes active surveys run on.
     *
     * @return list<array{survey_id: int, direction: string, route_id: int}>
     */
    public function activeTargets(): array
    {
        $rows = $this->db->query(
            'SELECT s.id AS survey_id, t.direction, t.route_id FROM ' . self::TARGETS . ' t
             JOIN ' . self::SURVEYS . " s ON s.family_id = t.family_id AND s.status = 'active'
             ORDER BY t.direction, t.route_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r): array => [
            'survey_id' => (int)$r['survey_id'],
            'direction' => (string)$r['direction'],
            'route_id'  => (int)$r['route_id'],
        ], $rows);
    }

    /**
     * Every version for the survey list, newest family first, with its targets
     * and its sessions since $since (test calls not counted).
     *
     * @return list<array<string, mixed>>
     */
    public function listing(int $since): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.family_id, s.version, s.name, s.status, s.language, s.created_at, s.activated_at, s.archived_at,
                (SELECT COUNT(*) FROM m_SurveySessions x WHERE x.survey_id = s.id AND x.is_test = 0 AND x.ended_at >= :since) AS offered,
                (SELECT COUNT(*) FROM m_SurveySessions x WHERE x.survey_id = s.id AND x.is_test = 0 AND x.ended_at >= :since
                    AND x.status != \'offered\') AS responded,
                (SELECT COUNT(*) FROM m_SurveySessions x WHERE x.survey_id = s.id AND x.is_test = 1) AS test_calls
             FROM ' . self::SURVEYS . ' s ORDER BY s.family_id DESC, s.version DESC'
        );
        $stmt->execute([':since' => $since]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $targets = [];
        foreach ($this->db->query('SELECT family_id, direction, route_id FROM ' . self::TARGETS)->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $targets[(int)$t['family_id']][] = ['direction' => (string)$t['direction'], 'route_id' => (int)$t['route_id']];
        }
        foreach ($rows as &$row) {
            foreach (['id', 'family_id', 'version', 'created_at', 'offered', 'responded', 'test_calls'] as $field) {
                $row[$field] = (int)$row[$field];
            }
            $row['targets'] = $targets[$row['family_id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    /** @return list<array{id: int, number: string, note: ?string, created_at: int}> */
    public function exclusionList(): array
    {
        return array_map(fn (array $r): array => [
            'id' => (int)$r['id'], 'number' => (string)$r['number'], 'note' => $r['note'], 'created_at' => (int)$r['created_at'],
        ], $this->db->query('SELECT id, number, note, created_at FROM ' . self::EXCLUSIONS . ' ORDER BY number')->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The latest test calls of a version, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function testCalls(int $surveyId, int $limit = 5): array
    {
        $stmt = $this->db->prepare('SELECT id, status, caller_num, ended_at FROM m_SurveySessions
            WHERE survey_id = ? AND is_test = 1 ORDER BY ended_at DESC, id DESC LIMIT ?');
        $stmt->bindValue(1, $surveyId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $answers = $this->db->prepare('SELECT position, value FROM m_SurveyAnswers WHERE session_id = ? ORDER BY position');
        foreach ($rows as &$row) {
            $answers->execute([$row['id']]);
            $row['answers'] = array_map('intval', $answers->fetchAll(PDO::FETCH_KEY_PAIR));
            $row['id'] = (int)$row['id'];
            $row['ended_at'] = (int)$row['ended_at'];
        }
        unset($row);

        return $rows;
    }

    /** @return list<array{direction: string, route_id: int}> */
    public function targetsOfFamily(int $familyId): array
    {
        $stmt = $this->db->prepare('SELECT direction, route_id FROM ' . self::TARGETS . ' WHERE family_id = ? ORDER BY direction, route_id');
        $stmt->execute([$familyId]);

        return array_map(fn (array $r): array => [
            'direction' => (string)$r['direction'],
            'route_id'  => (int)$r['route_id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<string> Excluded numbers, digits only. */
    public function exclusions(): array
    {
        return array_map('strval', $this->db->query('SELECT number FROM ' . self::EXCLUSIONS . ' ORDER BY number')
            ->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Incoming routes by ID, from the core's configuration database: the
     * provider ('' = any provider) and the DID ('' = any number).
     *
     * @param list<int> $ids
     *
     * @return array<int, array{provider: string, number: string}>
     */
    public static function incomingRoutes(PDO $config, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $config->prepare("SELECT id, provider, number FROM m_IncomingRoutingTable WHERE id IN ($marks)");
        $stmt->execute(array_values($ids));
        $routes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $routes[(int)$row['id']] = ['provider' => (string)$row['provider'], 'number' => (string)$row['number']];
        }

        return $routes;
    }

    /**
     * @param list<array<string, mixed>> $surveys
     *
     * @return list<array<string, mixed>>
     */
    private function withQuestions(array $surveys): array
    {
        if ($surveys === []) {
            return [];
        }
        $ids = array_map(fn (array $s): int => (int)$s['id'], $surveys);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare('SELECT * FROM ' . self::QUESTIONS . " WHERE survey_id IN ($marks) ORDER BY survey_id, position");
        $stmt->execute($ids);
        $questions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $questions[(int)$q['survey_id']][(int)$q['position']] = [
                'scale'           => (string)$q['scale'],
                'prompt_id'       => $q['prompt_id'] === null ? null : (int)$q['prompt_id'],
                'timeout_seconds' => (int)$q['timeout_seconds'],
                'retries'         => (int)$q['retries'],
            ];
        }
        foreach ($surveys as &$survey) {
            foreach (['id', 'family_id', 'version', 'min_talk_seconds', 'sample_percent', 'frequency_cap_days', 'created_at'] as $field) {
                $survey[$field] = (int)$survey[$field];
            }
            foreach (['intro_prompt_id', 'invalid_prompt_id', 'thanks_prompt_id', 'activated_at', 'archived_at'] as $field) {
                $survey[$field] = $survey[$field] === null ? null : (int)$survey[$field];
            }
            $survey['questions'] = $questions[$survey['id']] ?? [];
        }
        unset($survey);

        return $surveys;
    }
}
