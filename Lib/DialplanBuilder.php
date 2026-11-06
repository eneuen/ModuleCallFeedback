<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

/**
 * Builds the module's dialplan.
 *
 * Current stage: call-path probe (roadmap step 2). Every inbound and outbound
 * call gets a two-question test survey played with the built-in "beep" sound:
 * Q1 is one key (CSAT), Q2 is 0-10 (NPS, "1 then 0" for 10). Every decision
 * point writes a "CFPROBE" line to the Asterisk notice log, so test calls show
 * which values the real survey can rely on. Targeting, settings and storage
 * come later.
 *
 * Only fixed text, integers and validated IDs are ever written into the
 * dialplan; nothing typed by an admin or sent by a caller is.
 */
final class DialplanBuilder
{
    public const CTX_IN_ENTRY      = 'call-feedback-in';
    public const CTX_OUT_ENTRY     = 'call-feedback-out';
    public const CTX_SURVEY        = 'call-feedback-survey';
    public const CTX_NPS           = 'call-feedback-nps';
    public const CTX_HANGUP        = 'call-feedback-hangup';
    public const CTX_AGENT_IN_END  = 'call-feedback-agent-in-end';
    public const CTX_AGENT_OUT_END = 'call-feedback-agent-out-end';
    public const DB_TALK           = 'call-feedback-talk';

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

    /** Inbound, before the route's dial: once per DID pattern per context. */
    public static function incomingBeforeDial(string $routNumber): string
    {
        $number = preg_replace('/[^0-9A-Za-z!+_]/', '', $routNumber);
        return 'same => n,Log(NOTICE,CFPROBE event=before_dial pattern=' . $number
            . ' did=${EXTEN} ' . self::COMMON . ')' . "\n\t";
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
     * agent hangs up (F), and pass the route ID to the customer's channel.
     *
     * Talk time: when the agent hangs up, a handler on the agent's channel
     * stores the agent's ANSWEREDTIME in astdb under the call's linkedid, and
     * the survey reads and deletes it. It writes only when the agent hung up
     * first, the one case where the customer goes on to the survey, so no key
     * is left behind. The customer's CDR(answer) can't be
     * used: the CDR record is replaced moments after the bridge ends.
     */
    public static function outboundBeforeDial(array $rout): string
    {
        $routeId = (int)($rout['id'] ?? 0);
        return 'same => n,Set(DOPTIONS=${DOPTIONS}F(' . self::CTX_OUT_ENTRY . '^s^1))' . "\n\t"
            . 'same => n,Set(__CF_OUT_ROUTE=' . $routeId . ')' . "\n\t"
            . 'same => n,Set(CHANNEL(hangup_handler_push)=' . self::CTX_AGENT_OUT_END . ',s,1)' . "\n\t";
    }

    /** The module's own contexts. None of them is included in a routable context. */
    public static function contexts(): string
    {
        $c = self::COMMON;
        $in = self::CTX_IN_ENTRY;
        $out = self::CTX_OUT_ENTRY;
        $survey = self::CTX_SURVEY;
        $nps = self::CTX_NPS;
        $hangup = self::CTX_HANGUP;
        $agentIn = self::CTX_AGENT_IN_END;
        $agentOut = self::CTX_AGENT_OUT_END;
        $dbTalk = self::DB_TALK;

        return <<<DIALPLAN

[$in]
exten => s,1,Log(NOTICE,CFPROBE event=in_entry ctx=\${ARG1} did=\${FROM_DID} m_dialstatus=\${M_DIALSTATUS} dialstatus=\${DIALSTATUS} answeredtime=\${ANSWEREDTIME} dialedtime=\${DIALEDTIME} cf_talk=\${CF_TALK} peer=\${DIALEDPEERNUMBER} state=\${CHANNEL(state)} $c)
	same => n,GotoIf(\$["\${M_DIALSTATUS}" != "ANSWER"]?not_answered)
	same => n,ExecIf(\$["\${CF_ANSWER_AT}x" == "x"]?Wait(0.5))
	same => n,GotoIf(\$["\${CF_ANSWER_AT}x" == "x"]?no_agent)
	same => n,Set(CF_TALK=\$[\${EPOCH} - \${CF_ANSWER_AT}])
	same => n,Log(NOTICE,CFPROBE event=in_talk cf_talk=\${CF_TALK} answer_at=\${CF_ANSWER_AT} $c)
	same => n,Gosub($survey,s,1(in))
	same => n,Return()
	same => n(not_answered),Log(NOTICE,CFPROBE event=skip reason=not_answered $c)
	same => n,Return()
	same => n(no_agent),Log(NOTICE,CFPROBE event=skip reason=no_agent $c)
	same => n,Return()

[$out]
exten => s,1,Log(NOTICE,CFPROBE event=out_entry route=\${CF_OUT_ROUTE} now=\${EPOCH} $c)
	same => n,Set(CF_WAITS=0)
	same => n(read),Set(CF_TALK=\${DB_DELETE($dbTalk/\${CHANNEL(linkedid)})})
	same => n,GotoIf(\$["\${CF_TALK}x" != "x"]?talk)
	same => n,Set(CF_WAITS=\$[\${CF_WAITS} + 1])
	same => n,GotoIf(\$[\${CF_WAITS} > 8]?no_answer)
	same => n,Wait(0.25)
	same => n,Goto(read)
	same => n(talk),Log(NOTICE,CFPROBE event=out_talk cf_talk=\${CF_TALK} waits=\${CF_WAITS} $c)
	same => n,Gosub($survey,s,1(out))
	same => n,Hangup()
	same => n(no_answer),Log(NOTICE,CFPROBE event=skip reason=no_answer $c)
	same => n,Hangup()

[$survey]
exten => s,1,Set(CF_DIR=\${ARG1})
	same => n,Set(CHANNEL(hangup_handler_push)=$hangup,s,1)
	same => n,Set(CF_STATUS=offered)
	same => n,Set(CF_OFFERED=\${EPOCH})
	same => n,Log(NOTICE,CFPROBE event=offered dir=\${CF_DIR} $c)
	same => n,Read(CF_Q1,beep,1,,1,6)
	same => n,Log(NOTICE,CFPROBE event=q1 value=\${CF_Q1} readstatus=\${READSTATUS} $c)
	same => n,ExecIf(\$["\${CF_Q1}x" != "x"]?Set(CF_STATUS=started))
	same => n,Gosub($nps,s,1)
	same => n,Log(NOTICE,CFPROBE event=q2 value=\${CF_Q2} $c)
	same => n,Set(CF_STATUS=completed)
	same => n,Playback(beep)
	same => n,Return()

[$nps]
exten => s,1,Set(TIMEOUT(digit)=2)
	same => n,Set(TIMEOUT(response)=6)
	same => n,Playback(beep)
	same => n,WaitExten(6)
exten => _[0-9],1,Set(CF_Q2=\${EXTEN})
	same => n,Return()
exten => 10,1,Set(CF_Q2=10)
	same => n,Return()
exten => t,1,Set(CF_Q2=timeout)
	same => n,Return()
exten => i,1,Set(CF_Q2=invalid)
	same => n,Return()

[$hangup]
exten => s,1,Log(NOTICE,CFPROBE event=hangup dir=\${CF_DIR} status=\${CF_STATUS} q1=\${CF_Q1} q2=\${CF_Q2} offered=\${CF_OFFERED} now=\${EPOCH} $c)
	same => n,Return()

[$agentIn]
exten => s,1,Log(NOTICE,CFPROBE event=agent_in_end dialstatus=\${DIALSTATUS} answeredtime=\${ANSWEREDTIME} dialedtime=\${DIALEDTIME} from_chan=\${FROM_CHAN} master=\${MASTER_CHANNEL(CHANNEL)} $c)
	same => n,GotoIf(\$["\${DIALSTATUS}" != "ANSWER"]?done)
	same => n,GotoIf(\$["\${MASTER_CHANNEL(CF_ANSWER_AT)}x" != "x"]?done)
	same => n,Set(LOCAL(at)=\$[\${EPOCH} - 0\${ANSWEREDTIME}])
	same => n,Log(NOTICE,CFPROBE event=agent_in_answer at=\${at} now=\${EPOCH} $c)
	same => n,Set(MASTER_CHANNEL(CF_ANSWER_AT)=\${at})
	same => n,GotoIf(\$["\${CHANNEL_EXISTS(\${FROM_CHAN})}" != "1"]?done)
	same => n,Set(EXPORT(\${FROM_CHAN},CF_ANSWER_AT)=\${at})
	same => n(done),Return()

[$agentOut]
exten => s,1,Log(NOTICE,CFPROBE event=agent_out_end dialstatus=\${DIALSTATUS} answeredtime=\${ANSWEREDTIME} peer=\${DIALEDPEERNAME} hangupsource=\${CHANNEL(hangupsource)} $c)
	same => n,GotoIf(\$["\${DIALSTATUS}" != "ANSWER"]?done)
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
