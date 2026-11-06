<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use InvalidArgumentException;

/**
 * The end-of-call record the AGI script hands to the worker.
 *
 * The AGI script builds it from the survey's CF_* channel variables; the
 * worker validates it again when it arrives. Every field must match a fixed
 * pattern, so nothing outside the module's own value set reaches the
 * database: caller-controlled text (caller ID names, odd characters in
 * numbers) is either filtered out in the dialplan or rejected here.
 */
final class Handoff
{
    public const int VERSION = 1;

    public const array STATUSES = ['offered', 'started', 'completed', 'skipped'];

    /** Why an eligible call was not asked. Rule skips only; unanswered calls are not recorded. */
    public const array SKIP_REASONS = ['too_short', 'excluded', 'capped', 'sampled_out', 'concurrency'];

    public const array DIRECTIONS = ['in', 'out'];

    public const int MAX_QUESTIONS = 3;

    /** Channel variable => record field, with the pattern the value must match. */
    private const array FIELDS = [
        'CF_V'         => ['v', '/^[0-9]{1,3}$/'],
        'CF_SURVEY'    => ['survey', '/^[0-9]{1,9}$/'],
        'CF_DIR'       => ['direction', '/^(in|out)$/'],
        'CF_STATUS'    => ['status', '/^(offered|started|completed|skipped)$/'],
        'CF_REASON'    => ['reason', '/^([a-z_]{1,20})?$/'],
        'CF_CTX'       => ['context_id', '/^[A-Za-z0-9_-]{0,64}$/'],
        'CF_ROUTE'     => ['route_id', '/^[0-9]{0,9}$/'],
        'CF_DID'       => ['did', '/^[0-9*#+]{0,32}$/'],
        'CF_CALLER'    => ['caller', '/^[0-9+]{0,32}$/'],
        'CF_ANSWER_AT' => ['answer_at', '/^[0-9]{0,12}$/'],
        'CF_TALK'      => ['talk', '/^[0-9]{0,7}$/'],
        'CF_OFFERED'   => ['offered_at', '/^[0-9]{0,12}$/'],
        'CF_TEST'      => ['is_test', '/^1?$/'],
    ];

    private const string LINKEDID_PATTERN = '/^[A-Za-z0-9._-]{1,80}$/';
    private const string ANSWER_PATTERN = '/^([0-9]{1,2})?$/';
    private const string EPOCH_PATTERN = '/^[0-9]{0,12}$/';

    /**
     * The channel variables the AGI script reads, in addition to the linkedid.
     *
     * @return list<string>
     */
    public static function channelVariables(): array
    {
        $names = array_keys(self::FIELDS);
        for ($q = 1; $q <= self::MAX_QUESTIONS; $q++) {
            $names[] = "CF_A$q";
            $names[] = "CF_A{$q}_AT";
        }

        return $names;
    }

    /**
     * Builds a record from channel variables (as read by the AGI script).
     *
     * @param array<string, string> $vars Channel variable => value.
     *
     * @throws InvalidArgumentException When any value is outside its pattern.
     */
    public static function fromChannel(string $linkedId, array $vars, int $endedAt): array
    {
        $record = ['linkedid' => $linkedId, 'ended_at' => (string)$endedAt, 'answers' => []];
        foreach (self::FIELDS as $var => [$field]) {
            $record[$field] = (string)($vars[$var] ?? '');
        }
        for ($q = 1; $q <= self::MAX_QUESTIONS; $q++) {
            $value = (string)($vars["CF_A$q"] ?? '');
            if ($value !== '') {
                $record['answers'][(string)$q] = ['value' => $value, 'at' => (string)($vars["CF_A{$q}_AT"] ?? '')];
            }
        }

        return self::validate($record);
    }

    /**
     * Checks a record field by field and returns it with integers where the
     * values are numbers.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(mixed $record): array
    {
        if (!is_array($record)) {
            throw new InvalidArgumentException('Record is not an object');
        }
        $linkedId = self::string($record, 'linkedid');
        if (!preg_match(self::LINKEDID_PATTERN, $linkedId)) {
            throw new InvalidArgumentException('Invalid linkedid');
        }
        $out = ['linkedid' => $linkedId];
        foreach (self::FIELDS as [$field, $pattern]) {
            $value = self::string($record, $field);
            if (!preg_match($pattern, $value)) {
                throw new InvalidArgumentException("Invalid $field");
            }
            $out[$field] = $value;
        }
        $endedAt = self::string($record, 'ended_at');
        if (!preg_match('/^[0-9]{1,12}$/', $endedAt)) {
            throw new InvalidArgumentException('Invalid ended_at');
        }

        if ((int)$out['v'] !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported record version');
        }
        if ($out['status'] === 'skipped') {
            if (!in_array($out['reason'], self::SKIP_REASONS, true)) {
                throw new InvalidArgumentException('Invalid skip reason');
            }
        } elseif ($out['reason'] !== '') {
            throw new InvalidArgumentException('A reason is only allowed on a skipped call');
        }

        $answers = $record['answers'] ?? [];
        if (!is_array($answers) || count($answers) > self::MAX_QUESTIONS) {
            throw new InvalidArgumentException('Invalid answers');
        }
        $cleanAnswers = [];
        foreach ($answers as $position => $answer) {
            $position = (string)$position;
            if (!preg_match('/^[1-' . self::MAX_QUESTIONS . ']$/', $position) || !is_array($answer)) {
                throw new InvalidArgumentException('Invalid answer position');
            }
            $value = self::string($answer, 'value');
            $at = self::string($answer, 'at');
            if ($value === '' || !preg_match(self::ANSWER_PATTERN, $value) || !preg_match(self::EPOCH_PATTERN, $at)) {
                throw new InvalidArgumentException("Invalid answer $position");
            }
            $cleanAnswers[(int)$position] = ['value' => (int)$value, 'at' => $at === '' ? null : (int)$at];
        }
        if ($out['status'] === 'skipped' && $cleanAnswers !== []) {
            throw new InvalidArgumentException('A skipped call has no answers');
        }
        if ($out['is_test'] === '1' && $out['status'] === 'skipped') {
            throw new InvalidArgumentException('A test call is never skipped');
        }
        ksort($cleanAnswers);

        return [
            'linkedid'   => $linkedId,
            'survey'     => (int)$out['survey'],
            'direction'  => $out['direction'],
            'status'     => $out['status'],
            'reason'     => $out['reason'] === '' ? null : $out['reason'],
            'context_id' => $out['context_id'],
            'route_id'   => $out['route_id'] === '' ? null : (int)$out['route_id'],
            'did'        => $out['did'],
            'caller'     => $out['caller'],
            'answer_at'  => self::intOrNull($out['answer_at']),
            'talk'       => self::intOrNull($out['talk']),
            'offered_at' => self::intOrNull($out['offered_at']),
            'ended_at'   => (int)$endedAt,
            'is_test'    => $out['is_test'] === '1',
            'answers'    => $cleanAnswers,
        ];
    }

    /** The record as JSON for the queue: the same shape fromChannel() returns. */
    public static function encode(array $record): string
    {
        $wire = $record;
        foreach (['survey', 'route_id', 'answer_at', 'talk', 'offered_at', 'ended_at'] as $field) {
            $wire[$field] = $record[$field] === null ? '' : (string)$record[$field];
        }
        $wire['v'] = (string)self::VERSION;
        $wire['is_test'] = $record['is_test'] ? '1' : '';
        $wire['reason'] = (string)($record['reason'] ?? '');
        $wire['answers'] = [];
        foreach ($record['answers'] as $position => $answer) {
            $wire['answers'][(string)$position] = [
                'value' => (string)$answer['value'],
                'at'    => $answer['at'] === null ? '' : (string)$answer['at'],
            ];
        }

        return json_encode($wire, JSON_THROW_ON_ERROR);
    }

    /** @throws InvalidArgumentException */
    public static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Record is not valid JSON');
        }

        return self::validate($data);
    }

    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException("Invalid $key");
        }

        return $value;
    }

    private static function intOrNull(string $value): ?int
    {
        return $value === '' ? null : (int)$value;
    }
}
