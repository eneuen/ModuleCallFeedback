<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;
use PDO;

/**
 * Changes to surveys, as the configuration pages make them. Every value is
 * validated here, so nothing outside the allowed set reaches the database,
 * and from there the dialplan.
 *
 * - A draft can be edited freely. An active version's questions and prompts
 *   are fixed: editing them means a new draft version in the same family,
 *   so live wording never changes under existing results. Its rules
 *   (minimum talk time, sampling, frequency cap) can still change.
 * - Activation checks everything a live survey needs (activationProblems())
 *   and archives the family's previous active version.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class SurveyEditor
{
    public const int MAX_QUESTIONS = 3;
    public const int MAX_NAME_LENGTH = 60;
    public const int MAX_EXCLUSIONS = 5000;

    /** Field => [min, max] for a version's rules. */
    private const array RULES = [
        'min_talk_seconds'   => [0, 600],
        'sample_percent'     => [1, 100],
        'frequency_cap_days' => [0, 365],
    ];

    /** Field => [min, max] for a question's settings. */
    private const array QUESTION_LIMITS = [
        'timeout_seconds' => [2, 30],
        'retries'         => [0, 5],
    ];

    private const array PROMPTS = ['intro_prompt_id', 'invalid_prompt_id', 'thanks_prompt_id'];

    /** Every day of the week in the hours_days bit mask (Monday 1 ... Sunday 64). */
    public const int ALL_DAYS = 127;

    /** Modules that would survey the same calls. */
    private const array CONFLICTING_MODULES = ['ModuleQualityAssessment'];

    private readonly SurveyRepository $repository;

    /**
     * @param PDO $db     The module database, writable.
     * @param PDO $config The core's configuration database, read-only.
     */
    public function __construct(private readonly PDO $db, private readonly PDO $config)
    {
        $this->repository = new SurveyRepository($db);
    }

    /** A new survey family with its first draft version and a CSAT question. */
    public function create(string $name, string $language, int $now): int
    {
        $name = self::name($name);
        $language = self::language($language);

        return $this->transaction(function () use ($name, $language, $now): int {
            $this->db->prepare('INSERT INTO ' . SurveyRepository::SURVEYS . ' (family_id, version, name, status, language,
                min_talk_seconds, sample_percent, frequency_cap_days, hours_days, created_at) VALUES (0, 1, ?, ?, ?, 20, 100, 30, ?, ?)')
                ->execute([$name, SurveyRepository::DRAFT, $language, self::ALL_DAYS, $now]);
            $id = (int)$this->db->lastInsertId();
            $this->db->prepare('UPDATE ' . SurveyRepository::SURVEYS . ' SET family_id = id WHERE id = ?')->execute([$id]);
            $this->writeQuestions($id, [['scale' => ScalePresets::CSAT]]);

            return $id;
        });
    }

    /**
     * Updates a version. Name, language, prompts and questions only on a
     * draft; the rules on a draft or an active version.
     *
     * @param array<string, mixed> $fields
     *
     * @throws InvalidArgumentException
     */
    public function update(int $surveyId, array $fields): void
    {
        $survey = $this->survey($surveyId);
        $draftOnly = array_intersect(array_keys($fields), ['name', 'language', 'questions', ...self::PROMPTS]);
        if ($draftOnly !== [] && $survey['status'] !== SurveyRepository::DRAFT) {
            throw new InvalidArgumentException('Questions and prompts of an active survey are fixed. Create a new version to change them.');
        }
        if ($survey['status'] === SurveyRepository::ARCHIVED) {
            throw new InvalidArgumentException('An archived survey can no longer be changed.');
        }

        $set = [];
        foreach ($fields as $field => $value) {
            $set[$field] = match (true) {
                $field === 'name' => self::name((string)$value),
                $field === 'language' => self::language((string)$value),
                in_array($field, self::PROMPTS, true) => self::promptId($value),
                isset(self::RULES[$field]) => self::integer($field, $value, ...self::RULES[$field]),
                $field === 'hours_days' => self::integer($field, $value, 1, self::ALL_DAYS),
                in_array($field, ['hours_start', 'hours_end'], true) => self::time((string)$value),
                $field === 'questions' => null,
                default => throw new InvalidArgumentException("Unknown field: $field"),
            };
        }
        $start = array_key_exists('hours_start', $set) ? $set['hours_start'] : $survey['hours_start'];
        $end = array_key_exists('hours_end', $set) ? $set['hours_end'] : $survey['hours_end'];
        if (($start === '') !== ($end === '') || ($start !== '' && $start === $end)) {
            throw new InvalidArgumentException('Set both a start and an end time, or neither.');
        }
        $questions = isset($fields['questions']) ? self::questions($fields['questions']) : null;
        unset($set['questions']);

        $this->transaction(function () use ($surveyId, $set, $questions): void {
            foreach (['hours_start', 'hours_end'] as $field) {
                if (($set[$field] ?? null) === '') {
                    $set[$field] = null;
                }
            }
            if ($set !== []) {
                $assignments = implode(', ', array_map(fn (string $f): string => "$f = ?", array_keys($set)));
                $this->db->prepare('UPDATE ' . SurveyRepository::SURVEYS . " SET $assignments WHERE id = ?")
                    ->execute([...array_values($set), $surveyId]);
            }
            if ($questions !== null) {
                $this->writeQuestions($surveyId, $questions);
            }
        });
    }

    /**
     * Replaces a family's targets. Each is ['direction' => 'in'|'out', 'route_id' => id]
     * of an existing route; the default incoming rule can't be targeted.
     *
     * @param mixed $targets
     *
     * @throws InvalidArgumentException
     */
    public function setTargets(int $surveyId, mixed $targets): void
    {
        $survey = $this->survey($surveyId);
        if ($survey['status'] === SurveyRepository::ARCHIVED) {
            throw new InvalidArgumentException('An archived survey can no longer be changed.');
        }
        if (!is_array($targets)) {
            throw new InvalidArgumentException('Targets must be a list.');
        }
        $routes = $this->routes();
        $clean = [];
        foreach ($targets as $target) {
            $direction = is_array($target) ? (string)($target['direction'] ?? '') : '';
            $routeId = is_array($target) ? self::integer('route_id', $target['route_id'] ?? null, 1, PHP_INT_MAX) : 0;
            if (!isset($routes[$direction][$routeId])) {
                throw new InvalidArgumentException('Unknown route.');
            }
            $clean["$direction:$routeId"] = [$direction, $routeId];
        }
        if ($survey['status'] === SurveyRepository::ACTIVE) {
            $this->assertNoConflicts($survey['family_id'], array_values($clean));
        }

        $this->transaction(function () use ($survey, $clean): void {
            $this->db->prepare('DELETE FROM ' . SurveyRepository::TARGETS . ' WHERE family_id = ?')->execute([$survey['family_id']]);
            $insert = $this->db->prepare('INSERT INTO ' . SurveyRepository::TARGETS . ' (family_id, direction, route_id) VALUES (?, ?, ?)');
            foreach ($clean as [$direction, $routeId]) {
                $insert->execute([$survey['family_id'], $direction, $routeId]);
            }
        });
    }

    /**
     * Why a version can't be activated yet; empty when it can.
     *
     * @param callable(int, string): bool $promptUsable Whether a prompt ID can be played in a survey language.
     *
     * @return list<string> Message keys (translated by the page).
     */
    public function activationProblems(int $surveyId, callable $promptUsable): array
    {
        $survey = $this->survey($surveyId);
        if ($survey['status'] !== SurveyRepository::DRAFT) {
            return ['cf_ProblemNotDraft'];
        }
        $problems = [];
        if ($survey['questions'] === []) {
            $problems[] = 'cf_ProblemNoQuestions';
        }
        foreach (self::PROMPTS as $prompt) {
            // The invalid-key prompt is optional; intro and thanks are required.
            if ($prompt !== 'invalid_prompt_id' && $survey[$prompt] === null) {
                $problems[] = 'cf_ProblemMissingPrompt';
                break;
            }
        }
        $ids = array_filter([
            ...array_map(fn (string $p): ?int => $survey[$p], self::PROMPTS),
            ...array_column($survey['questions'], 'prompt_id'),
        ], fn (?int $id): bool => $id !== null);
        if (in_array(null, array_column($survey['questions'], 'prompt_id'), true)) {
            $problems[] = 'cf_ProblemMissingPrompt';
        }
        foreach ($ids as $id) {
            if (!$promptUsable($id, $survey['language'])) {
                $problems[] = 'cf_ProblemPromptMissingFile';
                break;
            }
        }
        if (!$this->hasTestCall($surveyId)) {
            $problems[] = 'cf_ProblemNoTestCall';
        }
        $targets = $this->repository->targetsOfFamily($survey['family_id']);
        if ($targets === []) {
            $problems[] = 'cf_ProblemNoTargets';
        }
        try {
            $this->assertNoConflicts($survey['family_id'], array_map(fn (array $t): array => [$t['direction'], $t['route_id']], $targets));
        } catch (InvalidArgumentException) {
            $problems[] = 'cf_ProblemTargetTaken';
        }
        if (in_array('in', array_column($targets, 'direction'), true) && $this->defaultActionPlaysSound()) {
            $problems[] = 'cf_ProblemDefaultActionPlayback';
        }
        if ($this->conflictingModuleEnabled()) {
            $problems[] = 'cf_ProblemOtherSurveyModule';
        }

        return array_values(array_unique($problems));
    }

    /**
     * Makes a draft live and archives the family's active version.
     *
     * @param callable(int, string): bool $promptUsable
     *
     * @throws InvalidArgumentException With the first problem when it can't be activated.
     */
    public function activate(int $surveyId, callable $promptUsable, int $now): void
    {
        $problems = $this->activationProblems($surveyId, $promptUsable);
        if ($problems !== []) {
            throw new InvalidArgumentException($problems[0]);
        }
        $survey = $this->survey($surveyId);
        $this->transaction(function () use ($survey, $now): void {
            $this->db->prepare('UPDATE ' . SurveyRepository::SURVEYS . " SET status = 'archived', archived_at = ?
                WHERE family_id = ? AND status = 'active'")->execute([$now, $survey['family_id']]);
            $this->db->prepare('UPDATE ' . SurveyRepository::SURVEYS . " SET status = 'active', activated_at = ? WHERE id = ?")
                ->execute([$now, $survey['id']]);
        });
    }

    /** Stops a survey: the version is kept for its results but runs no more. */
    public function archive(int $surveyId, int $now): void
    {
        $survey = $this->survey($surveyId);
        if ($survey['status'] === SurveyRepository::ARCHIVED) {
            return;
        }
        $this->db->prepare('UPDATE ' . SurveyRepository::SURVEYS . " SET status = 'archived', archived_at = ? WHERE id = ?")
            ->execute([$now, $surveyId]);
    }

    /**
     * Deletes a draft, which has never run: its questions and test calls go
     * with it, and its routes too when it was the family's only version.
     * Active and archived versions are kept for their results.
     *
     * @return int|null The family's newest remaining version, if any.
     *
     * @throws InvalidArgumentException When the version is not a draft.
     */
    public function deleteDraft(int $surveyId): ?int
    {
        $survey = $this->survey($surveyId);
        if ($survey['status'] !== SurveyRepository::DRAFT) {
            throw new InvalidArgumentException('Only a draft can be deleted. Archive an active survey instead.');
        }

        return $this->transaction(function () use ($survey): ?int {
            $id = $survey['id'];
            $this->db->prepare('DELETE FROM ' . FeedbackStore::ANSWERS . ' WHERE session_id IN
                (SELECT id FROM ' . FeedbackStore::SESSIONS . ' WHERE survey_id = ?)')->execute([$id]);
            $this->db->prepare('DELETE FROM ' . FeedbackStore::SESSIONS . ' WHERE survey_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM ' . SurveyRepository::QUESTIONS . ' WHERE survey_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM ' . SurveyRepository::SURVEYS . ' WHERE id = ?')->execute([$id]);
            $stmt = $this->db->prepare('SELECT id FROM ' . SurveyRepository::SURVEYS . ' WHERE family_id = ? ORDER BY version DESC LIMIT 1');
            $stmt->execute([$survey['family_id']]);
            $remaining = $stmt->fetchColumn();
            if ($remaining === false) {
                $this->db->prepare('DELETE FROM ' . SurveyRepository::TARGETS . ' WHERE family_id = ?')->execute([$survey['family_id']]);
                return null;
            }

            return (int)$remaining;
        });
    }

    /**
     * A new draft version of a family, copied from the given version. A family
     * has at most one draft; asking again returns it.
     */
    public function newVersion(int $surveyId, int $now): int
    {
        $survey = $this->survey($surveyId);
        $stmt = $this->db->prepare('SELECT id FROM ' . SurveyRepository::SURVEYS . " WHERE family_id = ? AND status = 'draft'");
        $stmt->execute([$survey['family_id']]);
        $draft = $stmt->fetchColumn();
        if ($draft !== false) {
            return (int)$draft;
        }

        return $this->copy($survey, $survey['family_id'], $now);
    }

    /** A new survey family, copied from the given version, without its targets. */
    public function duplicate(int $surveyId, int $now): int
    {
        $survey = $this->survey($surveyId);
        $survey['name'] = mb_substr($survey['name'], 0, self::MAX_NAME_LENGTH - 7) . ' (copy)';

        return $this->copy($survey, null, $now);
    }

    /**
     * Adds numbers to the exclusion list. Anything but digits is dropped from
     * each number ("+1 (555) 010-0100" becomes 15550100100).
     *
     * @param mixed $numbers
     *
     * @return int How many were new.
     */
    public function addExclusions(mixed $numbers, string $note, int $now): int
    {
        if (is_string($numbers)) {
            $numbers = preg_split('/[\r\n,;]+/', $numbers, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($numbers)) {
            throw new InvalidArgumentException('Numbers must be a list.');
        }
        $clean = [];
        foreach ($numbers as $number) {
            $digits = preg_replace('/[^0-9]/', '', (string)$number);
            if ($digits === '' || strlen($digits) > 20) {
                throw new InvalidArgumentException('Not a phone number: ' . mb_substr((string)$number, 0, 40));
            }
            $clean[$digits] = true;
        }
        $note = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $note) ?? '');
        $note = mb_substr($note, 0, 200);
        $count = (int)$this->db->query('SELECT COUNT(*) FROM ' . SurveyRepository::EXCLUSIONS)->fetchColumn();
        if ($count + count($clean) > self::MAX_EXCLUSIONS) {
            throw new InvalidArgumentException('Up to ' . self::MAX_EXCLUSIONS . ' excluded numbers.');
        }

        return $this->transaction(function () use ($clean, $note, $now): int {
            $insert = $this->db->prepare('INSERT INTO ' . SurveyRepository::EXCLUSIONS . ' (number, note, created_at)
                VALUES (?, ?, ?) ON CONFLICT (number) DO NOTHING');
            $added = 0;
            foreach (array_keys($clean) as $number) {
                $insert->execute([(string)$number, $note === '' ? null : $note, $now]);
                $added += $insert->rowCount();
            }

            return $added;
        });
    }

    public function removeExclusion(int $id): void
    {
        $this->db->prepare('DELETE FROM ' . SurveyRepository::EXCLUSIONS . ' WHERE id = ?')->execute([$id]);
    }

    /**
     * Incoming and outgoing routes a survey can target, by direction and ID.
     * The default incoming rule (priority 9999) is the fallback for calls no
     * route matched; it is not a route of its own.
     *
     * @return array{in: array<int, array<string, string>>, out: array<int, array<string, string>>}
     */
    public function routes(): array
    {
        $in = [];
        foreach ($this->config->query('SELECT id, rulename, number, provider FROM m_IncomingRoutingTable
            WHERE priority IS NULL OR priority < 9999 ORDER BY priority, id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $in[(int)$r['id']] = ['name' => (string)$r['rulename'], 'number' => (string)$r['number'], 'provider' => (string)$r['provider']];
        }
        $out = [];
        foreach ($this->config->query('SELECT id, rulename, numberbeginswith, providerid FROM m_OutgoingRoutingTable
            ORDER BY priority, id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = ['name' => (string)$r['rulename'], 'prefix' => (string)$r['numberbeginswith'], 'provider' => (string)$r['providerid']];
        }

        return ['in' => $in, 'out' => $out];
    }

    /**
     * The PBX's default incoming action. When it plays a sound file, the core
     * leaves out the after-dial hook on every route, so no inbound survey can run.
     */
    public function defaultActionPlaysSound(): bool
    {
        $row = $this->config->query("SELECT action, extension FROM m_IncomingRoutingTable WHERE priority = 9999 LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $row = $this->config->query('SELECT action, extension FROM m_IncomingRoutingTable WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        }

        return $row !== false && (string)$row['extension'] === '';
    }

    public function conflictingModuleEnabled(): bool
    {
        $marks = implode(',', array_fill(0, count(self::CONFLICTING_MODULES), '?'));
        $stmt = $this->config->prepare("SELECT COUNT(*) FROM m_PbxExtensionModules WHERE uniqid IN ($marks) AND disabled = '0'");
        $stmt->execute(self::CONFLICTING_MODULES);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasTestCall(int $surveyId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . FeedbackStore::SESSIONS . ' WHERE survey_id = ? AND is_test = 1');
        $stmt->execute([$surveyId]);

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @param list<array{string, int}> $targets [direction, route_id]
     *
     * @throws InvalidArgumentException When another family's active survey runs on one of them.
     */
    private function assertNoConflicts(int $familyId, array $targets): void
    {
        $taken = [];
        foreach ($this->repository->activeTargets() as $active) {
            $taken[$active['direction'] . ':' . $active['route_id']] = $active['survey_id'];
        }
        foreach ($targets as [$direction, $routeId]) {
            $owner = $taken["$direction:$routeId"] ?? null;
            if ($owner !== null && $this->survey($owner)['family_id'] !== $familyId) {
                throw new InvalidArgumentException('cf_ProblemTargetTaken');
            }
        }
    }

    /** @param array<string, mixed> $survey */
    private function copy(array $survey, ?int $familyId, int $now): int
    {
        return $this->transaction(function () use ($survey, $familyId, $now): int {
            $version = 1;
            if ($familyId !== null) {
                $stmt = $this->db->prepare('SELECT MAX(version) FROM ' . SurveyRepository::SURVEYS . ' WHERE family_id = ?');
                $stmt->execute([$familyId]);
                $version = (int)$stmt->fetchColumn() + 1;
            }
            $this->db->prepare('INSERT INTO ' . SurveyRepository::SURVEYS . ' (family_id, version, name, status, language,
                intro_prompt_id, invalid_prompt_id, thanks_prompt_id, min_talk_seconds, sample_percent, frequency_cap_days,
                hours_days, hours_start, hours_end, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $familyId ?? 0, $version, $survey['name'], SurveyRepository::DRAFT, $survey['language'],
                $survey['intro_prompt_id'], $survey['invalid_prompt_id'], $survey['thanks_prompt_id'],
                $survey['min_talk_seconds'], $survey['sample_percent'], $survey['frequency_cap_days'],
                $survey['hours_days'], $survey['hours_start'] === '' ? null : $survey['hours_start'],
                $survey['hours_end'] === '' ? null : $survey['hours_end'], $now,
            ]);
            $id = (int)$this->db->lastInsertId();
            if ($familyId === null) {
                $this->db->prepare('UPDATE ' . SurveyRepository::SURVEYS . ' SET family_id = id WHERE id = ?')->execute([$id]);
            }
            $this->writeQuestions($id, array_values($survey['questions']));

            return $id;
        });
    }

    /** @param list<array<string, mixed>> $questions Already validated, or defaults filled in here. */
    private function writeQuestions(int $surveyId, array $questions): void
    {
        $this->db->prepare('DELETE FROM ' . SurveyRepository::QUESTIONS . ' WHERE survey_id = ?')->execute([$surveyId]);
        $insert = $this->db->prepare('INSERT INTO ' . SurveyRepository::QUESTIONS . '
            (survey_id, position, scale, prompt_id, timeout_seconds, retries) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($questions as $i => $q) {
            $insert->execute([$surveyId, $i + 1, $q['scale'], $q['prompt_id'] ?? null, $q['timeout_seconds'] ?? 6, $q['retries'] ?? 2]);
        }
    }

    /**
     * @return list<array{scale: string, prompt_id: ?int, timeout_seconds: int, retries: int}>
     *
     * @throws InvalidArgumentException
     */
    private static function questions(mixed $questions): array
    {
        if (!is_array($questions) || $questions === [] || count($questions) > self::MAX_QUESTIONS) {
            throw new InvalidArgumentException('A survey has 1 to ' . self::MAX_QUESTIONS . ' questions.');
        }
        $clean = [];
        foreach ($questions as $i => $q) {
            $scale = is_array($q) ? (string)($q['scale'] ?? '') : '';
            if (!ScalePresets::exists($scale)) {
                throw new InvalidArgumentException('Unknown scale.');
            }
            if ($i === 0 && !ScalePresets::canBeHeadline($scale)) {
                throw new InvalidArgumentException('The first question must be a score (CSAT, NPS or CES).');
            }
            $clean[] = [
                'scale'           => $scale,
                'prompt_id'       => self::promptId($q['prompt_id'] ?? null),
                'timeout_seconds' => self::integer('timeout_seconds', $q['timeout_seconds'] ?? 6, ...self::QUESTION_LIMITS['timeout_seconds']),
                'retries'         => self::integer('retries', $q['retries'] ?? 2, ...self::QUESTION_LIMITS['retries']),
            ];
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private function survey(int $surveyId): array
    {
        $survey = $this->repository->find($surveyId);
        if ($survey === null) {
            throw new InvalidArgumentException('Unknown survey.');
        }

        return $survey;
    }

    private static function name(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('The name must be 1 to ' . self::MAX_NAME_LENGTH . ' characters.');
        }

        return $name;
    }

    /** 'HH:MM' (24-hour), or '' for no time limit. */
    private static function time(string $value): string
    {
        if ($value !== '' && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
            throw new InvalidArgumentException('Times must be HH:MM, 24-hour.');
        }

        return $value;
    }

    private static function language(string $language): string
    {
        if (!in_array($language, DialplanBuilder::LANGUAGES, true)) {
            throw new InvalidArgumentException('Unknown language.');
        }

        return $language;
    }

    /** A PBX sound-file ID, or one of the module's built-in prompts (negative IDs). */
    private static function promptId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0' || $value === 0) {
            return null;
        }
        if ((is_string($value) && preg_match('/^-[1-9][0-9]{0,2}$/', $value) === 1) || (is_int($value) && $value < 0)) {
            if (!BuiltInPrompts::isBuiltIn((int)$value)) {
                throw new InvalidArgumentException('Unknown built-in prompt.');
            }

            return (int)$value;
        }

        return self::integer('prompt', $value, 1, PHP_INT_MAX);
    }

    private static function integer(string $field, mixed $value, int $min, int $max): int
    {
        if (is_int($value)) {
            $value = (string)$value;
        }
        if (!is_string($value) || !preg_match('/^[0-9]{1,12}$/', $value) || (int)$value < $min || (int)$value > $max) {
            throw new InvalidArgumentException("$field must be a whole number from $min to $max.");
        }

        return (int)$value;
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
