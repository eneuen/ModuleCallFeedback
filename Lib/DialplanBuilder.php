<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

use PDO;

/**
 * Builds the module's dialplan from the stored surveys.
 *
 * - Active surveys run on their targeted routes only. Draft versions get
 *   their survey context too, but nothing routes to them except a test call.
 * - Nothing is generated for a direction no active survey targets, so with no
 *   active survey the module adds no dialplan to any call.
 * - During a survey everything lives in CF_* channel variables. When the
 *   customer's channel ends, the survey's hangup handler runs the module's
 *   AGI script once, which validates them and hands them to the worker.
 * - Every decision point writes a "CFPROBE" line to Asterisk's verbose log,
 *   for the CallSim probe scenarios and for troubleshooting.
 *
 * Only fixed text, integers, enum values, digit strings and sound-file paths
 * resolved by the core are written into the dialplan, each checked against a
 * pattern here. Survey names and anything a caller sends never are.
 */
final class DialplanBuilder
{
    public const CTX_IN_ENTRY      = 'call-feedback-in';
    public const CTX_IN_MATCH      = 'call-feedback-in-match';
    public const CTX_OUT_ENTRY     = 'call-feedback-out';
    public const CTX_TEST          = 'call-feedback-test';
    public const CTX_ANSWER        = 'call-feedback-answer';
    public const CTX_EXCLUDED      = 'call-feedback-excluded';
    public const CTX_HANGUP        = 'call-feedback-hangup';
    public const CTX_AGENT_IN_END  = 'call-feedback-agent-in-end';
    public const CTX_AGENT_OUT_END = 'call-feedback-agent-out-end';

    /** Set (inherited) on calls the module places itself, such as call-backs, so they are never surveyed. */
    public const SKIP_VARIABLE = 'CF_SKIP';

    /** astdb family for the outbound talk-time hand-over (see outboundBeforeDial). */
    public const DB_TALK = 'call-feedback-talk';

    /** astdb family for the frequency cap: digits of the number => time last offered. */
    public const DB_CAP = 'call-feedback-cap';

    /** GROUP() category for the concurrent-survey cap. */
    public const GROUP = 'call-feedback';

    /** The AGI script in agi-bin/, linked into Asterisk's AGI folder by the core. */
    public const AGI_SCRIPT = 'call_feedback_end.php';

    public const array LANGUAGES = ['en-en', 'ru-ru'];

    /** A survey call is hung up after this long, whatever happens in it. */
    public const int MAX_SURVEY_SECONDS = 180;

    /** Seconds Asterisk waits for a second key when a scale has two-digit answers (NPS "10"). */
    private const int DIGIT_TIMEOUT = 2;

    private const string PATH_PATTERN = '/^\/[A-Za-z0-9_\/.-]{1,250}$/';
    private const string PROVIDER_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
    private const string NUMBER_PATTERN = '/^[0-9]{1,32}$/';

    /** Values every probe log line carries. */
    private const string COMMON = 'linkedid=${CHANNEL(linkedid)} chan=${CHANNEL}';

    /** @var list<array<string, mixed>> */
    private array $surveys = [];

    /** @var list<array{survey_id: int, provider: string, number: string}> */
    private array $inboundTargets = [];

    /** @var array<int, int> Outgoing route ID => survey ID. */
    private array $outboundTargets = [];

    /** @var list<string> */
    private array $exclusions;

    /**
     * @param list<array<string, mixed>> $surveys Callable survey versions (SurveyRepository::callable()).
     * @param list<array{survey_id: int, direction: string, route_id: int}> $targets Active targets.
     * @param array<int, array{provider: string, number: string}> $incomingRoutes Incoming routes by ID.
     * @param callable(int): ?string $promptPath Sound file ID => path for Playback, without extension.
     *        Built-in prompts (negative IDs) never reach it: they play by their fixed name.
     * @param list<string> $exclusions
     */
    public function __construct(
        array $surveys,
        array $targets,
        array $incomingRoutes,
        callable $promptPath,
        array $exclusions,
        private readonly int $maxConcurrent,
    ) {
        $active = [];
        foreach ($surveys as $survey) {
            $survey['paths'] = [];
            foreach (['intro', 'invalid', 'thanks'] as $prompt) {
                $survey['paths'][$prompt] = self::path($promptPath, $survey[$prompt . '_prompt_id'] ?? null);
            }
            foreach ($survey['questions'] as $position => $question) {
                $survey['questions'][$position]['path'] = self::path($promptPath, $question['prompt_id']);
            }
            if (!in_array($survey['language'], self::LANGUAGES, true)) {
                $survey['language'] = self::LANGUAGES[0];
            }
            $this->surveys[] = $survey;
            if ($survey['status'] === SurveyRepository::ACTIVE) {
                $active[(int)$survey['id']] = true;
            }
        }

        foreach ($targets as $target) {
            if (!isset($active[$target['survey_id']])) {
                continue;
            }
            if ($target['direction'] === 'out') {
                $this->outboundTargets[$target['route_id']] = $target['survey_id'];
                continue;
            }
            $route = $incomingRoutes[$target['route_id']] ?? null;
            if ($route === null) {
                continue;
            }
            // '' means any provider / any number. Anything else must be a plain ID or digit string.
            if (($route['provider'] !== '' && !preg_match(self::PROVIDER_PATTERN, $route['provider']))
                || ($route['number'] !== '' && !preg_match(self::NUMBER_PATTERN, $route['number']))) {
                continue;
            }
            $this->inboundTargets[] = ['survey_id' => $target['survey_id']] + $route;
        }
        // Most specific route first, as the core picks routes: provider and number, number only, provider only, any.
        usort($this->inboundTargets, fn (array $a, array $b): int =>
            [($b['number'] !== '') * 2 + ($b['provider'] !== ''), $a['survey_id']]
            <=> [($a['number'] !== '') * 2 + ($a['provider'] !== ''), $b['survey_id']]);

        $this->exclusions = array_values(array_filter($exclusions, fn (string $n): bool => preg_match(self::NUMBER_PATTERN, $n) === 1));
    }

    /**
     * @param callable(int): ?string $promptPath
     */
    public static function fromDatabases(PDO $module, PDO $config, callable $promptPath): self
    {
        $repository = new SurveyRepository($module);
        $targets = $repository->activeTargets();
        $inboundIds = array_values(array_map(fn (array $t): int => $t['route_id'],
            array_filter($targets, fn (array $t): bool => $t['direction'] === 'in')));

        return new self(
            $repository->callable(),
            $targets,
            SurveyRepository::incomingRoutes($config, $inboundIds),
            $promptPath,
            $repository->exclusions(),
            (int)ModuleSettings::load($module)['max_concurrent'],
        );
    }

    /**
     * Inbound, after the route's dial (inside [<context>-incoming]). Gosub, not
     * Goto, so the admin's own after-dial-custom dialplan still runs.
     */
    public function incomingAfterDial(): string
    {
        return $this->inboundTargets === [] ? '' : 'same => n,Gosub(' . self::CTX_IN_ENTRY . ',s,1)' . "\n\t";
    }

    /**
     * [internal-users], just before an extension's phone is dialed, after the
     * core has wiped the hangup handlers. On inbound calls (FROM_DID set) a
     * handler is pushed on this agent leg. It runs either when the agent
     * answers (the core's Local channel is optimised out of the call, with
     * ANSWEREDTIME 0) or when the leg ends (queue members, ANSWEREDTIME = talk
     * so far); in both cases now - ANSWEREDTIME is when the agent answered. The
     * first such time is copied to the caller's channel as CF_ANSWER_AT, which
     * is the module's proof that a person answered.
     */
    public function internalUsersPreDial(): string
    {
        if ($this->inboundTargets === []) {
            return '';
        }

        return 'same => n,ExecIf($["${FROM_DID}x" != "x"]?Set(CHANNEL(hangup_handler_push)='
            . self::CTX_AGENT_IN_END . ',s,1))' . "\n\t";
    }

    /**
     * Outbound route, before its Dial, on targeted routes only: send the
     * customer to the survey when the agent hangs up (F), and pass the survey,
     * the route and the dialed number to the customer's channel.
     *
     * Talk time: when the agent hangs up, a handler on the agent's channel
     * stores the agent's ANSWEREDTIME in astdb under the call's linkedid, and
     * the survey reads and deletes it. It writes only when the agent hung up
     * first, the one case where the customer goes on to the survey, so no key
     * is left behind. The customer's CDR(answer) can't be used: the CDR record
     * is replaced moments after the bridge ends.
     *
     * @param array<string, mixed> $rout
     */
    public function outboundBeforeDial(array $rout): string
    {
        $routeId = (int)($rout['id'] ?? 0);
        $surveyId = $this->outboundTargets[$routeId] ?? null;
        if ($surveyId === null) {
            return '';
        }

        return 'same => n,ExecIf($["${' . self::SKIP_VARIABLE . '}" != "1"]?Set(DOPTIONS=${DOPTIONS}F(' . self::CTX_OUT_ENTRY . '^s^1)))' . "\n\t"
            . 'same => n,Set(__CF_OUT_SURVEY=' . $surveyId . ')' . "\n\t"
            . 'same => n,Set(__CF_OUT_ROUTE=' . $routeId . ')' . "\n\t"
            . 'same => n,Set(__CF_CUSTOMER=${FILTER(0-9+,${number})})' . "\n\t"
            . 'same => n,Set(CHANNEL(hangup_handler_push)=' . self::CTX_AGENT_OUT_END . ',s,1)' . "\n\t";
    }

    /** The module's own contexts. None of them is included in a routable context. */
    public function contexts(): string
    {
        if ($this->surveys === []) {
            return '';
        }
        $conf = $this->sharedContexts();
        if ($this->inboundTargets !== []) {
            $conf .= $this->inboundContexts();
        }
        if ($this->outboundTargets !== []) {
            $conf .= $this->outboundContexts();
        }
        foreach ($this->surveys as $survey) {
            $conf .= $this->surveyContexts($survey);
        }

        return $conf;
    }

    public static function surveyContext(int $surveyId): string
    {
        return "call-feedback-survey-$surveyId";
    }

    private static function questionContext(int $surveyId, int $position): string
    {
        return "call-feedback-q-$surveyId-$position";
    }

    /** Whether a resolved sound-file path may be written into the dialplan. */
    public static function isUsablePath(?string $path): bool
    {
        return $path !== null && preg_match(self::PATH_PATTERN, $path) === 1;
    }

    /** @param callable(int): ?string $promptPath */
    private static function path(callable $promptPath, mixed $id): ?string
    {
        if ($id !== null && BuiltInPrompts::isBuiltIn((int)$id)) {
            // A fixed name from the module's own list; Asterisk picks the survey's language folder.
            return BuiltInPrompts::playbackName((int)$id);
        }
        if ($id === null || (int)$id <= 0) {
            return null;
        }
        $path = $promptPath((int)$id);

        return self::isUsablePath($path) ? $path : null;
    }

    /**
     * Answers, exclusions, the test-call entry and the end-of-call hand-off.
     * A test call is an AMI Originate from the survey editor that rings an
     * extension and, once answered, enters [call-feedback-test] with the
     * survey ID as the extension. Nothing routes there otherwise.
     */
    private function sharedContexts(): string
    {
        $c = self::COMMON;
        $answer = self::CTX_ANSWER;
        $excluded = self::CTX_EXCLUDED;
        $test = self::CTX_TEST;
        $hangup = self::CTX_HANGUP;
        $agi = self::AGI_SCRIPT;

        $conf = <<<DIALPLAN

[$answer]
exten => s,1,Set(CF_A\${ARG1}=\${ARG2})
	same => n,Set(CF_A\${ARG1}_AT=\${EPOCH})
	same => n,ExecIf(\$["\${CF_STATUS}" == "offered"]?Set(CF_STATUS=started))
	same => n,Return()

[$test]
exten => _X!,1,Set(CF_TEST=1)
	same => n,Set(CF_DIR=in)
	same => n,Set(CF_CALLER=\${FILTER(0-9,\${CF_TEST_EXT})})
	same => n,Set(CF_ANSWER_AT=\${EPOCH})
	same => n,Set(CF_TALK=0)
	same => n,Verbose(3,CFPROBE event=test_entry survey=\${EXTEN} $c)
	same => n,GosubIf(\$[\${DIALPLAN_EXISTS(call-feedback-survey-\${FILTER(0-9,\${EXTEN})},s,1)}]?call-feedback-survey-\${FILTER(0-9,\${EXTEN})},s,1)
	same => n,Hangup()

[$hangup]
exten => s,1,Verbose(3,CFPROBE event=hangup dir=\${CF_DIR} survey=\${CF_SURVEY} status=\${CF_STATUS} reason=\${CF_REASON} a1=\${CF_A1} a2=\${CF_A2} a3=\${CF_A3} talk=\${CF_TALK} test=\${CF_TEST} $c)
	same => n,AGI($agi)
	same => n,Return()

[$excluded]

DIALPLAN;
        foreach ($this->exclusions as $number) {
            $conf .= "exten => $number,1,Return()\n";
        }

        return $conf;
    }

    private function inboundContexts(): string
    {
        $c = self::COMMON;
        $in = self::CTX_IN_ENTRY;
        $match = self::CTX_IN_MATCH;
        $agentIn = self::CTX_AGENT_IN_END;

        $conf = <<<DIALPLAN

[$in]
exten => s,1,Verbose(3,CFPROBE event=in_entry did=\${FROM_DID} provider=\${providerID} m_dialstatus=\${M_DIALSTATUS} answer_at=\${CF_ANSWER_AT} $c)
	same => n,GotoIf(\$["\${M_DIALSTATUS}" != "ANSWER"]?not_answered)
	same => n,ExecIf(\$["\${CF_ANSWER_AT}x" == "x"]?Wait(0.5))
	same => n,GotoIf(\$["\${CF_ANSWER_AT}x" == "x"]?no_agent)
	same => n,Set(CF_DIR=in)
	same => n,Set(CF_CTX=\${FILTER(A-Za-z0-9_-,\${providerID})})
	same => n,Set(CF_DID=\${FILTER(0-9*#+,\${FROM_DID})})
	same => n,Set(CF_CALLER=\${FILTER(0-9+,\${CALLERID(num)})})
	same => n,Set(CF_TALK=\$[\${EPOCH} - \${CF_ANSWER_AT}])
	same => n,Verbose(3,CFPROBE event=in_talk cf_talk=\${CF_TALK} answer_at=\${CF_ANSWER_AT} $c)
	same => n,Goto($match,s,1)
	same => n(not_answered),Verbose(3,CFPROBE event=skip reason=not_answered $c)
	same => n,Return()
	same => n(no_agent),Verbose(3,CFPROBE event=skip reason=no_agent $c)
	same => n,Return()

[$match]

DIALPLAN;
        $first = true;
        foreach ($this->inboundTargets as $target) {
            $conditions = [];
            if ($target['provider'] !== '') {
                $conditions[] = '"${providerID}" == "' . $target['provider'] . '"';
            }
            if ($target['number'] !== '') {
                $conditions[] = '"${FROM_DID}" == "' . $target['number'] . '"';
            }
            $condition = $conditions === [] ? '1' : implode(' & ', $conditions);
            $conf .= ($first ? 'exten => s,1,' : "\tsame => n,")
                . 'GotoIf($[' . $condition . ']?' . self::surveyContext($target['survey_id']) . ',s,1)' . "\n";
            $first = false;
        }
        $conf .= "\tsame => n,Verbose(3,CFPROBE event=skip reason=not_targeted $c)\n";
        $conf .= "\tsame => n,Return()\n";

        $conf .= <<<DIALPLAN

[$agentIn]
exten => s,1,GotoIf(\$["\${DIALSTATUS}" != "ANSWER"]?done)
	same => n,GotoIf(\$["\${MASTER_CHANNEL(CF_ANSWER_AT)}x" != "x"]?done)
	same => n,Set(LOCAL(at)=\$[\${EPOCH} - 0\${ANSWEREDTIME}])
	same => n,Set(MASTER_CHANNEL(CF_ANSWER_AT)=\${at})
	same => n,GotoIf(\$["\${CHANNEL_EXISTS(\${FROM_CHAN})}" != "1"]?done)
	same => n,Set(EXPORT(\${FROM_CHAN},CF_ANSWER_AT)=\${at})
	same => n(done),Return()

DIALPLAN;

        return $conf;
    }

    private function outboundContexts(): string
    {
        $c = self::COMMON;
        $out = self::CTX_OUT_ENTRY;
        $agentOut = self::CTX_AGENT_OUT_END;
        $dbTalk = self::DB_TALK;

        // The customer arrives here by an F() jump, not a Gosub: no LOCAL() variables.
        return <<<DIALPLAN

[$out]
exten => s,1,Verbose(3,CFPROBE event=out_entry survey=\${CF_OUT_SURVEY} route=\${CF_OUT_ROUTE} $c)
	same => n,Set(CF_DIR=out)
	same => n,Set(CF_ROUTE=\${FILTER(0-9,\${CF_OUT_ROUTE})})
	same => n,Set(CF_CALLER=\${CF_CUSTOMER})
	same => n,Set(CF_WAITS=0)
	same => n(read),Set(CF_TALK=\${DB_DELETE($dbTalk/\${CHANNEL(linkedid)})})
	same => n,GotoIf(\$["\${CF_TALK}x" != "x"]?talk)
	same => n,Set(CF_WAITS=\$[\${CF_WAITS} + 1])
	same => n,GotoIf(\$[\${CF_WAITS} > 8]?no_answer)
	same => n,Wait(0.25)
	same => n,Goto(read)
	same => n(talk),Set(CF_ANSWER_AT=\$[\${EPOCH} - \${CF_TALK}])
	same => n,Verbose(3,CFPROBE event=out_talk cf_talk=\${CF_TALK} waits=\${CF_WAITS} $c)
	same => n,Set(CF_SURVEY_CTX=call-feedback-survey-\${FILTER(0-9,\${CF_OUT_SURVEY})})
	same => n,GosubIf(\$[\${DIALPLAN_EXISTS(\${CF_SURVEY_CTX},s,1)}]?\${CF_SURVEY_CTX},s,1)
	same => n,Hangup()
	same => n(no_answer),Verbose(3,CFPROBE event=skip reason=no_answer $c)
	same => n,Hangup()

[$agentOut]
exten => s,1,GotoIf(\$["\${DIALSTATUS}" != "ANSWER"]?done)
	same => n,GotoIf(\$["\${CHANNEL(hangupsource)}" != "\${CHANNEL}"]?done)
	same => n,Set(DB($dbTalk/\${CHANNEL(linkedid)})=\${ANSWEREDTIME})
	same => n(done),Return()

DIALPLAN;
    }

    /**
     * One survey version: the rules, then each question, then thanks. A skipped
     * call returns at once with its reason; the hangup handler reports it.
     *
     * @param array<string, mixed> $survey
     */
    private function surveyContexts(array $survey): string
    {
        $id = (int)$survey['id'];
        $c = self::COMMON;
        $minTalk = max(0, (int)$survey['min_talk_seconds']);
        $sample = max(0, min(100, (int)$survey['sample_percent']));
        $capSeconds = max(0, (int)$survey['frequency_cap_days']) * 86400;
        $max = max(1, $this->maxConcurrent);
        $dbCap = self::DB_CAP;
        $group = self::GROUP;
        $excluded = self::CTX_EXCLUDED;
        $hangup = self::CTX_HANGUP;
        $language = $survey['language'];

        $lines = [
            'Set(CF_V=' . Handoff::VERSION . ')',
            "Set(CF_SURVEY=$id)",
            "Set(CHANNEL(hangup_handler_push)=$hangup,s,1)",
            'Set(CF_NUM=${FILTER(0-9,${CF_CALLER})})',
            // A test call from the survey editor skips the rules.
            'GotoIf($["${CF_TEST}" == "1"]?ask)',
            "GotoIf(\$[0\${CF_TALK} < $minTalk]?too_short)",
            ...self::hoursCheck($survey),
            "GotoIf(\$[\"\${CF_NUM}x\" != \"x\" & \${DIALPLAN_EXISTS($excluded,\${CF_NUM},1)}]?excluded)",
        ];
        if ($capSeconds > 0) {
            $lines[] = "ExecIf(\$[\"\${CF_NUM}x\" != \"x\"]?Set(CF_LAST=\${DB($dbCap/\${CF_NUM})}))";
            $lines[] = "GotoIf(\$[\"\${CF_LAST}x\" != \"x\" & \${EPOCH} - 0\${CF_LAST} < $capSeconds]?capped)";
        }
        if ($sample < 100) {
            $lines[] = "GotoIf(\$[\${RAND(1,100)} > $sample]?sampled_out)";
        }
        $lines[] = "Set(GROUP($group)=surveys)";
        $lines[] = "GotoIf(\$[\${GROUP_COUNT(surveys@$group)} > $max]?concurrency)";
        $lines[] = ['ask', 'Set(CF_STATUS=offered)'];
        // However keys are pressed (or not), the survey ends within this time.
        $lines[] = 'Set(TIMEOUT(absolute)=' . self::MAX_SURVEY_SECONDS . ')';
        $lines[] = 'Set(CF_OFFERED=${EPOCH})';
        if ($capSeconds > 0) {
            $lines[] = "ExecIf(\$[\"\${CF_NUM}x\" != \"x\" & \"\${CF_TEST}\" != \"1\"]?Set(DB($dbCap/\${CF_NUM})=\${EPOCH}))";
        }
        $lines[] = "Set(CHANNEL(language)=$language)";
        $lines[] = "Verbose(3,CFPROBE event=offered dir=\${CF_DIR} survey=$id $c)";
        if ($survey['paths']['intro'] !== null) {
            $lines[] = 'Playback(' . $survey['paths']['intro'] . ')';
        }
        $positions = array_keys($survey['questions']);
        sort($positions);
        foreach ($positions as $position) {
            $lines[] = 'Gosub(' . self::questionContext($id, $position) . ',s,1)';
            // No answer to the headline question: don't go on to the others.
            if ($position === $positions[0]) {
                $lines[] = "GotoIf(\$[\"\${CF_A$position}x\" == \"x\"]?done)";
            }
        }
        if ($positions !== []) {
            $last = end($positions);
            $lines[] = "ExecIf(\$[\"\${CF_A$last}x\" != \"x\"]?Set(CF_STATUS=completed))";
        }
        $lines[] = ['done', $survey['paths']['thanks'] !== null ? 'Playback(' . $survey['paths']['thanks'] . ')' : 'NoOp()'];
        $lines[] = 'Return()';
        foreach (Handoff::SKIP_REASONS as $reason) {
            $lines[] = [$reason, 'Set(CF_STATUS=skipped)'];
            $lines[] = "Set(CF_REASON=$reason)";
            $lines[] = "Verbose(3,CFPROBE event=skip reason=$reason cf_talk=\${CF_TALK} $c)";
            $lines[] = 'Return()';
        }

        $conf = "\n[" . self::surveyContext($id) . "]\n" . self::priorities('s', $lines);
        foreach ($positions as $position) {
            $conf .= $this->questionContexts($survey, $position);
        }

        return $conf;
    }

    private const array WEEKDAYS = [1 => 'mon', 2 => 'tue', 4 => 'wed', 8 => 'thu', 16 => 'fri', 32 => 'sat', 64 => 'sun'];

    /**
     * The hours rule as GotoIfTime lines: within the days and times the call
     * goes on, otherwise it is skipped as outside_hours. Nothing when the
     * survey runs at all times. PBX time zone; an end before the start runs
     * past midnight.
     *
     * @param array<string, mixed> $survey
     *
     * @return list<string|array{string, string}>
     */
    private static function hoursCheck(array $survey): array
    {
        $mask = (int)($survey['hours_days'] ?? 127) & 127;
        $start = (string)($survey['hours_start'] ?? '');
        $end = (string)($survey['hours_end'] ?? '');
        $timePattern = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';
        $hasTimes = preg_match($timePattern, $start) === 1 && preg_match($timePattern, $end) === 1 && $start !== $end;
        if (($mask === 127 || $mask === 0) && !$hasTimes) {
            return [];
        }
        $days = $mask === 127 || $mask === 0 ? '*' : implode('&', array_values(array_filter(self::WEEKDAYS,
            fn (int $bit): bool => ($mask & $bit) !== 0, ARRAY_FILTER_USE_KEY)));
        $times = $hasTimes ? "$start-$end" : '*';

        return ["GotoIfTime($times,$days,*,*?in_hours)", 'Goto(outside_hours)', ['in_hours', 'NoOp()']];
    }

    /**
     * One question: play the prompt, wait for a key of the scale, retry on an
     * invalid key or silence, give up after the retries.
     *
     * @param array<string, mixed> $survey
     */
    private function questionContexts(array $survey, int $position): string
    {
        $question = $survey['questions'][$position];
        $scale = (string)$question['scale'];
        $timeout = max(1, min(30, (int)$question['timeout_seconds']));
        $retries = max(0, min(5, (int)$question['retries']));
        $digitTimeout = ScalePresets::needsDigitTimeout($scale) ? self::DIGIT_TIMEOUT : 1;
        $prompt = $question['path'] ?? 'beep';
        $invalid = $survey['paths']['invalid'];

        $conf = "\n[" . self::questionContext((int)$survey['id'], $position) . "]\n" . self::priorities('s', [
            'Set(CF_TRY=0)',
            ['ask', "Set(TIMEOUT(digit)=$digitTimeout)"],
            "Set(TIMEOUT(response)=$timeout)",
            "Background($prompt)",
            "WaitExten($timeout)",
            ['retry', 'Set(CF_TRY=$[${CF_TRY} + 1])'],
            "GotoIf(\$[\${CF_TRY} > $retries]?give_up)",
            $invalid !== null ? "Playback($invalid)" : 'NoOp()',
            'Goto(ask)',
            ['give_up', 'Return()'],
        ]);
        foreach (ScalePresets::keys($scale) as $key => $value) {
            $conf .= "exten => $key,1,Gosub(" . self::CTX_ANSWER . ",s,1($position,$value))\n\tsame => n,Return()\n";
        }
        $conf .= "exten => i,1,Goto(s,retry)\nexten => t,1,Goto(s,retry)\n";

        return $conf;
    }

    /**
     * One extension's priorities. A line is an application string, or
     * [label, application] for a labelled priority.
     *
     * @param list<string|array{string, string}> $lines
     */
    private static function priorities(string $exten, array $lines): string
    {
        $out = '';
        foreach ($lines as $i => $line) {
            [$label, $app] = is_array($line) ? $line : [null, $line];
            $priority = $i === 0 ? '1' : 'n';
            $prefix = $i === 0 ? "exten => $exten," : "\tsame => ";
            $out .= $prefix . $priority . ($label !== null ? "($label)" : '') . ',' . $app . "\n";
        }

        return $out;
    }
}
