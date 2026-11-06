<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

/**
 * Builds the module's dialplan.
 *
 * Until surveys can be configured, every answered inbound and outbound call
 * gets the built-in test survey (SurveyCatalog::builtIn(), beep prompts):
 * Q1 is one key (CSAT 1-5), Q2 is 0-10 (NPS, "1 then 0" for 10). Every
 * decision point also writes a "CFPROBE" line to the Asterisk notice log for
 * the CallSim probe scenarios.
 *
 * During the survey everything lives in CF_* channel variables. When the
 * customer's channel ends, the survey's hangup handler runs the module's AGI
 * script once, which validates the variables and hands them to the worker.
 *
 * Only fixed text, integers and validated IDs are ever written into the
 * dialplan; nothing typed by an admin or sent by a caller is.
 */
final class DialplanBuilder
{
    public const CTX_IN_ENTRY      = 'call-feedback-in';
    public const CTX_OUT_ENTRY     = 'call-feedback-out';
    public const CTX_SURVEY        = 'call-feedback-survey';
    public const CTX_ANSWER        = 'call-feedback-answer';
    public const CTX_NPS           = 'call-feedback-nps';
    public const CTX_HANGUP        = 'call-feedback-hangup';
    public const CTX_AGENT_IN_END  = 'call-feedback-agent-in-end';
    public const CTX_AGENT_OUT_END = 'call-feedback-agent-out-end';

    /** astdb family for the outbound talk-time hand-over (see outboundBeforeDial). */
    public const DB_TALK = 'call-feedback-talk';

    /** The AGI script in agi-bin/, linked into Asterisk's AGI folder by the core. */
    public const AGI_SCRIPT = 'call_feedback_end.php';

    /** Values every probe log line carries. */
    private const COMMON = 'linkedid=${CHANNEL(linkedid)} chan=${CHANNEL}';

    /**
     * Inbound, after the route's dial (inside [<context>-incoming]).
     * Gosub, not Goto, so the admin's own after-dial-custom dialplan still runs.
     */
    public static function incomingAfterDial(string $contextId): string
    {
        $id = self::safeId($contextId);
        return 'same => n,Gosub(' . self::CTX_IN_ENTRY . ',s,1(' . $id . '))' . "\n\t";
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
    public static function internalUsersPreDial(): string
    {
        return 'same => n,ExecIf($["${FROM_DID}x" != "x"]?Set(CHANNEL(hangup_handler_push)='
            . self::CTX_AGENT_IN_END . ',s,1))' . "\n\t";
    }

    /**
     * Outbound route, before its Dial: send the customer to the survey when the
     * agent hangs up (F), and pass the route ID and the dialed number to the
     * customer's channel.
     *
     * Talk time: when the agent hangs up, a handler on the agent's channel
     * stores the agent's ANSWEREDTIME in astdb under the call's linkedid, and
     * the survey reads and deletes it. It writes only when the agent hung up
     * first, the one case where the customer goes on to the survey, so no key
     * is left behind. The customer's CDR(answer) can't be used: the CDR record
     * is replaced moments after the bridge ends.
     */
    public static function outboundBeforeDial(array $rout): string
    {
        $routeId = (int)($rout['id'] ?? 0);
        return 'same => n,Set(DOPTIONS=${DOPTIONS}F(' . self::CTX_OUT_ENTRY . '^s^1))' . "\n\t"
            . 'same => n,Set(__CF_OUT_ROUTE=' . $routeId . ')' . "\n\t"
            . 'same => n,Set(__CF_CUSTOMER=${FILTER(0-9+,${number})})' . "\n\t"
            . 'same => n,Set(CHANNEL(hangup_handler_push)=' . self::CTX_AGENT_OUT_END . ',s,1)' . "\n\t";
    }

    /** The module's own contexts. None of them is included in a routable context. */
    public static function contexts(): string
    {
        $survey = SurveyCatalog::builtIn();
        $minTalk = (int)$survey['min_talk_seconds'];
        $surveyId = (int)$survey['id'];
        [$q1Min, $q1Max] = [(int)$survey['questions'][1]['min'], (int)$survey['questions'][1]['max']];

        $c = self::COMMON;
        $in = self::CTX_IN_ENTRY;
        $out = self::CTX_OUT_ENTRY;
        $surveyCtx = self::CTX_SURVEY;
        $answer = self::CTX_ANSWER;
        $nps = self::CTX_NPS;
        $hangup = self::CTX_HANGUP;
        $agentIn = self::CTX_AGENT_IN_END;
        $agentOut = self::CTX_AGENT_OUT_END;
        $dbTalk = self::DB_TALK;
        $agi = self::AGI_SCRIPT;

        return <<<DIALPLAN

[$in]
exten => s,1,Log(NOTICE,CFPROBE event=in_entry ctx=\${ARG1} did=\${FROM_DID} m_dialstatus=\${M_DIALSTATUS} answer_at=\${CF_ANSWER_AT} $c)
	same => n,GotoIf(\$["\${M_DIALSTATUS}" != "ANSWER"]?not_answered)
	same => n,ExecIf(\$["\${CF_ANSWER_AT}x" == "x"]?Wait(0.5))
	same => n,GotoIf(\$["\${CF_ANSWER_AT}x" == "x"]?no_agent)
	same => n,Set(CF_DIR=in)
	same => n,Set(CF_CTX=\${ARG1})
	same => n,Set(CF_DID=\${FILTER(0-9*#+,\${FROM_DID})})
	same => n,Set(CF_CALLER=\${FILTER(0-9+,\${CALLERID(num)})})
	same => n,Set(CF_TALK=\$[\${EPOCH} - \${CF_ANSWER_AT}])
	same => n,Log(NOTICE,CFPROBE event=in_talk cf_talk=\${CF_TALK} answer_at=\${CF_ANSWER_AT} $c)
	same => n,Gosub($surveyCtx,s,1)
	same => n,Return()
	same => n(not_answered),Log(NOTICE,CFPROBE event=skip reason=not_answered $c)
	same => n,Return()
	same => n(no_agent),Log(NOTICE,CFPROBE event=skip reason=no_agent $c)
	same => n,Return()

[$out]
exten => s,1,Log(NOTICE,CFPROBE event=out_entry route=\${CF_OUT_ROUTE} now=\${EPOCH} $c)
	same => n,Set(CF_DIR=out)
	same => n,Set(CF_ROUTE=\${CF_OUT_ROUTE})
	same => n,Set(CF_CALLER=\${CF_CUSTOMER})
	same => n,Set(CF_WAITS=0)
	same => n(read),Set(CF_TALK=\${DB_DELETE($dbTalk/\${CHANNEL(linkedid)})})
	same => n,GotoIf(\$["\${CF_TALK}x" != "x"]?talk)
	same => n,Set(CF_WAITS=\$[\${CF_WAITS} + 1])
	same => n,GotoIf(\$[\${CF_WAITS} > 8]?no_answer)
	same => n,Wait(0.25)
	same => n,Goto(read)
	same => n(talk),Set(CF_ANSWER_AT=\$[\${EPOCH} - \${CF_TALK}])
	same => n,Log(NOTICE,CFPROBE event=out_talk cf_talk=\${CF_TALK} waits=\${CF_WAITS} $c)
	same => n,Gosub($surveyCtx,s,1)
	same => n,Hangup()
	same => n(no_answer),Log(NOTICE,CFPROBE event=skip reason=no_answer $c)
	same => n,Hangup()

[$surveyCtx]
exten => s,1,Set(CF_V=1)
	same => n,Set(CF_SURVEY=$surveyId)
	same => n,Set(CHANNEL(hangup_handler_push)=$hangup,s,1)
	same => n,GotoIf(\$[\${CF_TALK} < $minTalk]?too_short)
	same => n,Set(CF_STATUS=offered)
	same => n,Set(CF_OFFERED=\${EPOCH})
	same => n,Log(NOTICE,CFPROBE event=offered dir=\${CF_DIR} $c)
	same => n,Read(CF_KEY,beep,1,,1,6)
	same => n,ExecIf(\$[\${REGEX("^[$q1Min-$q1Max]$" \${CF_KEY})}]?Gosub($answer,s,1(1,\${CF_KEY})))
	same => n,Gosub($nps,s,1)
	same => n,ExecIf(\$["\${CF_NPS}x" != "x"]?Gosub($answer,s,1(2,\${CF_NPS})))
	same => n,ExecIf(\$["\${CF_A2}x" != "x"]?Set(CF_STATUS=completed))
	same => n,Playback(beep)
	same => n,Return()
	same => n(too_short),Set(CF_STATUS=skipped)
	same => n,Set(CF_REASON=too_short)
	same => n,Log(NOTICE,CFPROBE event=skip reason=too_short cf_talk=\${CF_TALK} $c)
	same => n,Return()

[$answer]
exten => s,1,Set(CF_A\${ARG1}=\${ARG2})
	same => n,Set(CF_A\${ARG1}_AT=\${EPOCH})
	same => n,ExecIf(\$["\${CF_STATUS}" == "offered"]?Set(CF_STATUS=started))
	same => n,Return()

[$nps]
exten => s,1,Set(TIMEOUT(digit)=2)
	same => n,Set(TIMEOUT(response)=6)
	same => n,Playback(beep)
	same => n,WaitExten(6)
exten => _[0-9],1,Set(CF_NPS=\${EXTEN})
	same => n,Return()
exten => 10,1,Set(CF_NPS=10)
	same => n,Return()
exten => t,1,Return()
exten => i,1,Return()

[$hangup]
exten => s,1,Log(NOTICE,CFPROBE event=hangup dir=\${CF_DIR} status=\${CF_STATUS} reason=\${CF_REASON} a1=\${CF_A1} a2=\${CF_A2} talk=\${CF_TALK} $c)
	same => n,AGI($agi)
	same => n,Return()

[$agentIn]
exten => s,1,GotoIf(\$["\${DIALSTATUS}" != "ANSWER"]?done)
	same => n,GotoIf(\$["\${MASTER_CHANNEL(CF_ANSWER_AT)}x" != "x"]?done)
	same => n,Set(LOCAL(at)=\$[\${EPOCH} - 0\${ANSWEREDTIME}])
	same => n,Set(MASTER_CHANNEL(CF_ANSWER_AT)=\${at})
	same => n,GotoIf(\$["\${CHANNEL_EXISTS(\${FROM_CHAN})}" != "1"]?done)
	same => n,Set(EXPORT(\${FROM_CHAN},CF_ANSWER_AT)=\${at})
	same => n(done),Return()

[$agentOut]
exten => s,1,GotoIf(\$["\${DIALSTATUS}" != "ANSWER"]?done)
	same => n,GotoIf(\$["\${CHANNEL(hangupsource)}" != "\${CHANNEL}"]?done)
	same => n,Set(DB($dbTalk/\${CHANNEL(linkedid)})=\${ANSWEREDTIME})
	same => n(done),Return()

DIALPLAN;
    }

    /** Context IDs come from the core (provider IDs, "none"); keep only safe characters. */
    private static function safeId(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
        return $clean === '' ? 'none' : $clean;
    }
}
