<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * The results pages: Overview and Breakdowns for one survey version over a
 * date range. Versions are never mixed, so different wordings are never
 * averaged together. Test calls and sessions the CDR excluded (talk under
 * the minimum) are left out of scores; excluded ones are counted separately.
 *
 * Sessions are read once and aggregated in PHP, in the PBX's time zone, so
 * hours and weekdays are local. Every parameter is validated here; no
 * request value reaches SQL except as a bound parameter.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class Results
{
    public const array BREAKDOWNS = ['agent', 'queue', 'did', 'direction', 'hour', 'weekday', 'wait', 'talk'];

    /** Bucket lower bounds in seconds, with the key shown for each ("0-15", "15-30" ... "300+"). */
    private const array WAIT_BUCKETS = [0, 15, 30, 60, 120, 300];
    private const array TALK_BUCKETS = [0, 60, 180, 300, 600];

    /** Longest range a page may ask for. */
    private const int MAX_DAYS = 400;

    /** Ranges longer than this are trended by week. */
    private const int DAILY_UP_TO_DAYS = 62;

    /** Ranges of at least this many days are trended by week when a typical day has too few responses for a score. */
    private const int WEEKLY_FROM_DAYS = 14;

    public function __construct(private readonly PDO $db, private readonly int $minSample)
    {
    }

    /**
     * Survey versions that can be shown, newest first: every non-draft version.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(): array
    {
        $rows = $this->db->query("SELECT s.id, s.family_id, s.version, s.name, s.status, q.scale
            FROM m_Surveys s LEFT JOIN m_SurveyQuestions q ON q.survey_id = s.id AND q.position = 1
            WHERE s.status != 'draft' ORDER BY s.status = 'active' DESC, s.activated_at DESC, s.id DESC")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r): array => [
            'id' => (int)$r['id'], 'family_id' => (int)$r['family_id'], 'version' => (int)$r['version'],
            'name' => (string)$r['name'], 'status' => (string)$r['status'], 'scale' => (string)$r['scale'],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $q survey, from, to (YYYY-MM-DD), direction ('' | in | out)
     *
     * @return array<string, mixed>
     */
    public function overview(array $q): array
    {
        [$survey, $from, $to, $direction] = $this->params($q);
        [$prevFrom, $prevTo] = self::previousPeriod($from, $to);
        $headline = $survey['questions'][1]['scale'];
        $resolutionAt = null;
        foreach ($survey['questions'] as $position => $question) {
            if ($position > 1 && $question['scale'] === ScalePresets::YES_NO) {
                $resolutionAt = $position;
                break;
            }
        }

        // One pass over the period, keeping counts and answer values only.
        $total = $counted = $started = $completed = 0;
        $values = $resolutionValues = $byDay = [];
        foreach ($this->sessions($survey['id'], $from, $to, $direction) as $s) {
            $total++;
            if ($s['excluded']) {
                continue;
            }
            $counted++;
            $started += $s['status'] !== 'offered' ? 1 : 0;
            $completed += $s['status'] === 'completed' ? 1 : 0;
            if (isset($s['answers'][1])) {
                $values[] = $s['answers'][1];
                $byDay[date('Y-m-d', $s['ended_at'])][] = $s['answers'][1];
            }
            if ($resolutionAt !== null && isset($s['answers'][$resolutionAt])) {
                $resolutionValues[] = $s['answers'][$resolutionAt];
            }
        }
        $previousValues = [];
        foreach ($this->sessions($survey['id'], $prevFrom, $prevTo, $direction) as $s) {
            if (!$s['excluded'] && isset($s['answers'][1])) {
                $previousValues[] = $s['answers'][1];
            }
        }

        return [
            'survey'          => ['id' => $survey['id'], 'name' => $survey['name'], 'version' => $survey['version'], 'scale' => $headline],
            'from'            => $from->format('Y-m-d'),
            'to'              => $to->format('Y-m-d'),
            'min_sample'      => $this->minSample,
            'score'           => Scores::summary($headline, $values),
            'previous'        => ['from' => $prevFrom->format('Y-m-d'), 'to' => $prevTo->format('Y-m-d'),
                'score' => Scores::summary($headline, $previousValues)],
            'resolution'      => $resolutionAt === null ? null
                : Scores::summary(ScalePresets::YES_NO, $resolutionValues) + ['position' => $resolutionAt],
            'offered'         => $counted,
            'started'         => $started,
            'completed'       => $completed,
            'excluded'        => $total - $counted,
            'response_rate'   => self::percent($started, $counted),
            'completion_rate' => self::percent($completed, $started),
            'trend'           => $this->trend($headline, $byDay, count($values), $from, $to),
            'followups'       => $this->followUps($survey['id'], $from, $to, $direction),
            'skipped'         => $this->skipped($survey['id'], $from, $to, $direction),
        ];
    }

    /**
     * Score by one dimension, largest groups first.
     *
     * @param array<string, mixed> $q as overview(), plus breakdown (one of BREAKDOWNS)
     *
     * @return array{breakdown: string, scale: string, min_sample: int, rows: list<array<string, mixed>>}
     */
    public function breakdown(array $q): array
    {
        [$survey, $from, $to, $direction] = $this->params($q);
        $breakdown = (string)($q['breakdown'] ?? '');
        if (!in_array($breakdown, self::BREAKDOWNS, true)) {
            throw new InvalidArgumentException('Unknown breakdown.');
        }
        $headline = $survey['questions'][1]['scale'];
        $groups = [];
        foreach ($this->sessions($survey['id'], $from, $to, $direction) as $s) {
            if ($s['excluded'] || !isset($s['answers'][1])) {
                continue;
            }
            $key = match ($breakdown) {
                'hour'    => (int)date('G', $s['ended_at']),
                'weekday' => (int)date('N', $s['ended_at']),
                'wait'    => self::bucket($s['wait'], self::WAIT_BUCKETS),
                'talk'    => self::bucket($s['talk'], self::TALK_BUCKETS),
                default   => $s[$breakdown] ?? '',
            };
            $groups[(string)$key][] = $s['answers'][1];
        }
        $rows = [];
        foreach ($groups as $key => $values) {
            $summary = Scores::summary($headline, $values);
            $rows[] = ['key' => (string)$key, 'responses' => $summary['responses'], 'score' => $summary['score'],
                'average' => $summary['average'], 'low_sample' => $summary['responses'] < $this->minSample];
        }
        usort($rows, in_array($breakdown, ['hour', 'weekday', 'wait', 'talk'], true)
            ? fn (array $a, array $b): int => [$a['key'] === '', (int)$a['key']] <=> [$b['key'] === '', (int)$b['key']]
            : fn (array $a, array $b): int => [$b['responses'], $a['key']] <=> [$a['responses'], $b['key']]);

        return ['breakdown' => $breakdown, 'scale' => $headline, 'min_sample' => $this->minSample, 'rows' => $rows];
    }

    /**
     * How the version's eligible calls went: how many a rule left unasked,
     * how far surveyed callers got, and where they left.
     *
     * Eligible calls are those an agent answered on the survey's routes: the
     * rule skips plus every survey session. A session found too short in the
     * CDR after the survey is counted with the "too short" rule, not as
     * surveyed. A caller's progress is the last question they answered: a
     * missed headline question ends the survey, but a missed later question
     * moves on, so "left at question k" means the last answer was question
     * k-1, and someone who skips question 2 but answers question 3 got to
     * the end.
     *
     * @param array<string, mixed> $q as overview()
     *
     * @return array<string, mixed>
     */
    public function health(array $q): array
    {
        [$survey, $from, $to, $direction] = $this->params($q);
        $skipped = $this->skipped($survey['id'], $from, $to, $direction);
        $positions = array_keys($survey['questions']);
        sort($positions);

        // One pass: how many questions each caller got through (the index of
        // their last answer), and how many answered each question.
        $total = $counted = 0;
        $progress = array_fill(0, count($positions) + 1, 0);
        $answered = array_fill_keys($positions, 0);
        foreach ($this->sessions($survey['id'], $from, $to, $direction) as $s) {
            $total++;
            if ($s['excluded']) {
                continue;
            }
            $counted++;
            $last = 0;
            foreach ($positions as $i => $position) {
                if (isset($s['answers'][$position])) {
                    $last = $i + 1;
                    $answered[$position]++;
                }
            }
            $progress[$last]++;
        }
        $questions = [];
        $reached = $counted;
        foreach ($positions as $i => $position) {
            $left = $progress[$i];
            $questions[] = [
                'position' => $position,
                'scale'    => $survey['questions'][$position]['scale'],
                'reached'  => $reached,
                'answered' => $answered[$position],
                'left'     => $left,
                'left_rate' => self::percent($left, $reached),
            ];
            $reached -= $left;
        }

        return [
            'survey'    => ['id' => $survey['id'], 'name' => $survey['name'], 'version' => $survey['version']],
            'eligible'  => array_sum($skipped) + $total,
            'surveyed'  => $counted,
            'finished'  => $reached,
            'questions' => $questions,
            'skipped'   => $skipped,
            'excluded'  => $total - $counted,
        ];
    }

    /** The bucket's lower bound as the key, or '' when the value is unknown. */
    private static function bucket(?int $seconds, array $bounds): string
    {
        if ($seconds === null) {
            return '';
        }
        $key = $bounds[0];
        foreach ($bounds as $bound) {
            if ($seconds >= $bound) {
                $key = $bound;
            }
        }

        return (string)$key;
    }

    /**
     * The period of the same length just before [from, to].
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public static function previousPeriod(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $days = (int)$from->diff($to)->days + 1;

        return [$from->modify("-$days days"), $from->modify('-1 day')];
    }

    /**
     * @return array{array<string, mixed>, DateTimeImmutable, DateTimeImmutable, string}
     *
     * @throws InvalidArgumentException
     */
    private function params(array $q): array
    {
        $surveyId = (string)($q['survey'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $surveyId)) {
            throw new InvalidArgumentException('Choose a survey.');
        }
        $survey = (new SurveyRepository($this->db))->find((int)$surveyId);
        if ($survey === null || !isset($survey['questions'][1])) {
            throw new InvalidArgumentException('Unknown survey.');
        }
        $from = self::date((string)($q['from'] ?? ''));
        $to = self::date((string)($q['to'] ?? ''));
        if ($to < $from || (int)$from->diff($to)->days + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('Choose a range of up to ' . self::MAX_DAYS . ' days.');
        }
        $direction = (string)($q['direction'] ?? '');
        if (!in_array($direction, ['', 'in', 'out'], true)) {
            throw new InvalidArgumentException('Unknown direction.');
        }

        return [$survey, $from, $to, $direction];
    }

    private static function date(string $value): DateTimeImmutable
    {
        // Checked as text first: createFromFormat() throws on a NUL byte instead of returning false.
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('Dates must be YYYY-MM-DD.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Dates must be YYYY-MM-DD.');
        }

        return $date;
    }

    /**
     * Sessions of a version that ended in [from, to] (local days), with their
     * answers, one at a time. Results only keep counts and answer values, so a
     * year of a busy PBX's surveys never has to fit in PHP's memory at once
     * (the cabinet runs with PHP's default 128 MB).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function sessions(int $surveyId, DateTimeImmutable $from, DateTimeImmutable $to, string $direction): \Generator
    {
        $sql = 'SELECT s.id, s.status, s.direction, s.did, s.agent, s.queue, s.ended_at, s.excluded, s.wait_seconds, s.cdr_talk_seconds,
                a.position, a.value
            FROM m_SurveySessions s LEFT JOIN m_SurveyAnswers a ON a.session_id = s.id
            WHERE s.survey_id = ? AND s.is_test = 0 AND s.ended_at >= ? AND s.ended_at < ?';
        $params = [$surveyId, $from->getTimestamp(), $to->modify('+1 day')->getTimestamp()];
        if ($direction !== '') {
            $sql .= ' AND s.direction = ?';
            $params[] = $direction;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY s.id');
        $stmt->execute($params);
        $current = null;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($current === null || $current['id'] !== (int)$row['id']) {
                if ($current !== null) {
                    yield $current;
                }
                $current = [
                    'id' => (int)$row['id'], 'status' => (string)$row['status'], 'direction' => (string)$row['direction'],
                    'did' => (string)$row['did'], 'agent' => (string)$row['agent'], 'queue' => (string)$row['queue'],
                    'ended_at' => (int)$row['ended_at'], 'excluded' => (int)$row['excluded'] === 1, 'answers' => [],
                    'wait' => $row['wait_seconds'] === null ? null : (int)$row['wait_seconds'],
                    'talk' => $row['cdr_talk_seconds'] === null ? null : (int)$row['cdr_talk_seconds'],
                ];
            }
            if ($row['position'] !== null) {
                $current['answers'][(int)$row['position']] = (int)$row['value'];
            }
        }
        if ($current !== null) {
            yield $current;
        }
    }

    /**
     * Score and responses per day, or per week (from Monday) on long ranges
     * and on ranges of two weeks or more where an average day has fewer
     * responses than a score needs, so the line isn't mostly gaps.
     *
     * @param array<string, list<int>> $byDay Headline answers by local day (Y-m-d).
     *
     * @return array{interval: string, points: list<array<string, mixed>>}
     */
    private function trend(string $scale, array $byDay, int $answered, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $days = (int)$from->diff($to)->days + 1;
        $weekly = $days > self::DAILY_UP_TO_DAYS
            || ($days >= self::WEEKLY_FROM_DAYS && $answered < $days * $this->minSample);
        $buckets = [];
        $start = $weekly ? $from->modify('monday this week') : $from;
        for ($d = $start; $d <= $to; $d = $d->modify($weekly ? '+1 week' : '+1 day')) {
            $buckets[$d->format('Y-m-d')] = [];
        }
        foreach ($byDay as $day => $values) {
            $key = $weekly ? (new DateTimeImmutable($day))->modify('monday this week')->format('Y-m-d') : $day;
            array_push($buckets[$key], ...$values);
        }
        $points = [];
        foreach ($buckets as $key => $values) {
            $summary = Scores::summary($scale, $values);
            $points[] = ['period' => $key, 'responses' => $summary['responses'], 'score' => $summary['score'],
                'low_sample' => $summary['responses'] < $this->minSample];
        }

        return ['interval' => $weekly ? 'week' : 'day', 'points' => $points];
    }

    /**
     * Follow-ups opened for the period's calls, by status.
     *
     * @return array{open: int, contacted: int, resolved: int}
     */
    private function followUps(int $surveyId, DateTimeImmutable $from, DateTimeImmutable $to, string $direction): array
    {
        $sql = 'SELECT f.status, COUNT(*) FROM m_FollowUps f JOIN m_SurveySessions s ON s.id = f.session_id
            WHERE s.survey_id = ? AND s.is_test = 0 AND s.ended_at >= ? AND s.ended_at < ?';
        $params = [$surveyId, $from->getTimestamp(), $to->modify('+1 day')->getTimestamp()];
        if ($direction !== '') {
            $sql .= ' AND s.direction = ?';
            $params[] = $direction;
        }
        $stmt = $this->db->prepare($sql . ' GROUP BY f.status');
        $stmt->execute($params);
        $counts = ['open' => 0, 'contacted' => 0, 'resolved' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$status, $n]) {
            if (isset($counts[$status])) {
                $counts[$status] = (int)$n;
            }
        }

        return $counts;
    }

    /** @return array<string, int> Skip reason => calls not asked. */
    private function skipped(int $surveyId, DateTimeImmutable $from, DateTimeImmutable $to, string $direction): array
    {
        $sql = 'SELECT reason, SUM(count) FROM m_SkipCounts WHERE survey_id = ? AND day >= ? AND day <= ?';
        $params = [$surveyId, $from->format('Y-m-d'), $to->format('Y-m-d')];
        if ($direction !== '') {
            $sql .= ' AND direction = ?';
            $params[] = $direction;
        }
        $stmt = $this->db->prepare($sql . ' GROUP BY reason');
        $stmt->execute($params);
        $skipped = array_fill_keys(Handoff::SKIP_REASONS, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$reason, $count]) {
            $skipped[(string)$reason] = (int)$count;
        }

        return $skipped;
    }

    private static function percent(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round(100 * $part / $whole, 1);
    }
}
