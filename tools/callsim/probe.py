#!/usr/bin/env python3
"""Survey probe: real calls through the test PBX, checked against the module's log and database.

The module's dialplan writes a "CFPROBE event=... key=value" line to
Asterisk's verbose log at every survey decision. Each scenario here places a
real call with CallSim's phones and carrier, presses survey keys from the
customer's side after the agent hangs up, then checks the CFPROBE lines the
call produced and the rows the module's worker stored for it (session,
answers, CDR attribution, skip counts).

  probe.py list            list the scenarios
  probe.py run [names...]  run all scenarios (or the named ones)

Needs `callsim.py setup` first, and the same environment variables.
Results are printed and saved to tools/callsim/probe-results/<scenario>.json
(local only, never committed).
"""
import json
import re
import sys
import time
from pathlib import Path

import urllib.parse
import urllib.request

import callsim
from callsim import (CALLER, DID_DIRECT, DID_MAIN, IVR, OUTBOUND_NUMBER, OUTBOUND_PREFIX, PBX_CONTAINER, PBX_URL,
                     Line, Pbx, callsim_routes_in_hours, docker, pause)

HERE = Path(__file__).resolve().parent
RESULTS_DIR = HERE / "probe-results"
MESSAGES_LOG = "/storage/usbdisk1/mikopbx/log/asterisk/verbose"
MODULE_DB = "/storage/usbdisk1/mikopbx/custom_modules/ModuleCallFeedback/db/module.db"
ATTRIBUTION_TIMEOUT = 75     # the worker attributes every 30 s, 15 s after a call ends
LINE_RE = re.compile(r"CFPROBE event=(\S+)(.*)$")
PAIR_RE = re.compile(r"(\w+)=(\S*)")


# --------------------------------------------------------------------------- log capture

def log_size():
    out = docker("exec", PBX_CONTAINER, "sh", "-c", f"wc -c < {MESSAGES_LOG}", capture=True)
    return int(out.strip() or 0)


def probe_events(since_bytes):
    """CFPROBE lines written after the given log offset, as dicts in log order."""
    out = docker("exec", PBX_CONTAINER, "sh", "-c",
                 f"tail -c +{since_bytes + 1} {MESSAGES_LOG} | grep CFPROBE || true", capture=True)
    events = []
    for line in out.splitlines():
        # The verbose log also traces each Verbose() call ("Executing [...] Verbose(...)"); keep the message only.
        if "Executing [" in line:
            continue
        found = LINE_RE.search(line)
        if not found:
            continue
        event = {"event": found.group(1), "time": line[:19]}
        event.update(dict(PAIR_RE.findall(found.group(2))))
        events.append(event)
    return events


def wait_quiet(since_bytes, quiet=4, timeout=40):
    """Waits until no new CFPROBE lines have arrived for `quiet` seconds."""
    deadline = time.time() + timeout
    last, changed = -1, time.time()
    while time.time() < deadline:
        n = len(probe_events(since_bytes))
        if n != last:
            last, changed = n, time.time()
        elif time.time() - changed >= quiet:
            break
        time.sleep(1)
    return probe_events(since_bytes)


# --------------------------------------------------------------------------- module database

def db_rows(sql):
    out = docker("exec", PBX_CONTAINER, "sqlite3", "-json", MODULE_DB, sql, capture=True)
    return json.loads(out) if out.strip() else []


def linkedids(events):
    return sorted({e["linkedid"] for e in events if e.get("linkedid")})


def sessions_of(ids):
    if not ids:
        return []
    marks = ",".join("'" + i.replace("'", "") + "'" for i in ids)
    rows = db_rows(f"SELECT * FROM m_SurveySessions WHERE linkedid IN ({marks})")
    for row in rows:
        row["answers"] = {str(a["position"]): str(a["value"]) for a in db_rows(
            f"SELECT position, value FROM m_SurveyAnswers WHERE session_id = {int(row['id'])}")}
    return rows


SKIP_REASONS = ("too_short", "outside_hours", "excluded", "capped", "sampled_out", "concurrency")


def skip_totals():
    rows = db_rows("SELECT reason, SUM(count) AS n FROM m_SkipCounts GROUP BY reason")
    totals = {r: 0 for r in SKIP_REASONS}
    totals.update({row["reason"]: int(row["n"]) for row in rows})
    return totals


def wait_for_sessions(ids, attributed, timeout):
    """The stored sessions of these calls; with `attributed`, waits until the worker has filled in the CDR fields."""
    deadline = time.time() + timeout
    rows = sessions_of(ids)
    while time.time() < deadline:
        rows = sessions_of(ids)
        if rows and (not attributed or all(r["attributed_at"] for r in rows)):
            break
        time.sleep(3)
    return rows


def page_post(action, data):
    """A write action of the module's page, as the page sends it: AJAX POST with the admin's Bearer token."""
    pbx = Pbx()
    req = urllib.request.Request(f"{PBX_URL}/admin-cabinet/module-call-feedback/{action}",
                                 data=urllib.parse.urlencode(data).encode(), method="POST",
                                 headers={"Authorization": f"Bearer {pbx.token}", "X-Requested-With": "XMLHttpRequest",
                                          "Accept": "application/json"})
    try:
        with urllib.request.urlopen(req, context=pbx.tls, timeout=30) as r:
            body = json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        # A refused request (400) carries the page's message, which some scenarios expect.
        body = json.loads(e.read() or b"{}")
    if not body.get("success"):
        raise RuntimeError(f"{action}: {body.get('message')}")
    return body.get("data")


def active_survey_id():
    rows = db_rows("SELECT id FROM m_Surveys WHERE status = 'active' ORDER BY id DESC LIMIT 1")
    if not rows:
        raise RuntimeError("No active survey; set one up on CallSim's routes first (see the README)")
    return int(rows[0]["id"])


# --------------------------------------------------------------------------- helpers

def safe_hangup(*lines):
    for line in lines:
        try:
            line.hangup()
        except Exception:  # noqa: BLE001 - already closed
            pass


class ivr_action:
    """Adds one IVR menu action for the length of a scenario (e.g. 4 -> hangup)."""

    def __init__(self, digits, extension):
        self.digits, self.extension = digits, extension

    def _ivr(self):
        for item in self.pbx.items("ivr-menu"):
            if item.get("extension") == IVR:
                return self.pbx.get(f"/ivr-menu/{item['id']}")["data"]
        raise RuntimeError("CallSim IVR not found")

    def _set(self, actions):
        ivr = self._ivr()
        self.pbx.patch(f"/ivr-menu/{ivr['id']}",
                       {"actions": [{"digits": a["digits"], "extension": a["extension"]} for a in actions]})
        pause(8)  # dialplan reload

    def __enter__(self):
        self.pbx = Pbx()
        self.saved = self._ivr()["actions"]
        self._set([*self.saved, {"digits": self.digits, "extension": self.extension}])
        return self

    def __exit__(self, *exc):
        self._set(self.saved)
        return False


def first(events, name):
    return next((e for e in events if e["event"] == name), None)


def num(value):
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


# --------------------------------------------------------------------------- scenarios

SCENARIOS = []


def scenario(description, expect):
    """`expect(result)` returns a list of failed checks (empty when the call behaved).

    `result` has the call's CFPROBE "events", its stored "sessions" and the
    change in "skips" (too-short count) during the scenario.
    """
    def wrap(fn):
        SCENARIOS.append({"name": fn.__name__, "description": description, "run": fn, "expect": expect})
        return fn
    return wrap


def surveyed(direction, answers, talk, agent, status="completed", queue=None, follow_up=False):
    """Expectation: survey offered in `direction`, `answers` ({"1": "4", ...}) stored with
    `status`, dialplan talk time about `talk` s, and the CDR attributing it to `agent`. With
    `follow_up`, the headline answer is a detractor and opens a follow-up (otherwise none)."""
    def check(result):
        events, problems = result["events"], []
        offered = first(events, "offered")
        if not offered or offered.get("dir") != direction:
            problems.append(f"survey not offered ({direction})")
        hangups = [e for e in events if e["event"] == "hangup"]
        if len(hangups) != 1:
            problems.append(f"survey hangup handler ran {len(hangups)} times")
        talk_event = first(events, "in_talk" if direction == "in" else "out_talk")
        measured = num(talk_event.get("cf_talk")) if talk_event else None
        if measured is None:
            problems.append("no agent talk time on the customer's channel")
        elif abs(measured - talk) > 2:
            problems.append(f"agent talk time {measured}, expected about {talk}")

        sessions = result["sessions"]
        if len(sessions) != 1:
            return problems + [f"{len(sessions)} stored sessions, expected 1"]
        row = sessions[0]
        for field, expected in (("direction", direction), ("status", status), ("agent", agent),
                                ("queue", queue), ("excluded", 0)):
            if row.get(field) != expected:
                problems.append(f"stored {field}={row.get(field)!r}, expected {expected!r}")
        if row["answers"] != answers:
            problems.append(f"stored answers {row['answers']}, expected {answers}")
        if not row.get("attributed_at"):
            problems.append("session not attributed from the CDR")
        elif row.get("cdr_talk_seconds") is None or abs(row["cdr_talk_seconds"] - talk) > 2:
            problems.append(f"CDR talk {row.get('cdr_talk_seconds')}, expected about {talk}")
        cases = db_rows(f"SELECT status FROM m_FollowUps WHERE session_id = {int(row['id'])}")
        if follow_up and [c["status"] for c in cases] != ["open"]:
            problems.append(f"follow-ups {cases}, expected one open")
        if not follow_up and cases:
            problems.append(f"unexpected follow-up {cases}")
        return problems
    return check


def not_surveyed(result):
    problems = ["survey was offered"] if first(result["events"], "offered") else []
    if result["sessions"]:
        problems.append(f"{len(result['sessions'])} session(s) stored")
    return problems


def skipped(reason, surveyed_first=False):
    """Expectation: the (last) call is skipped for `reason` and counted; with `surveyed_first`,
    an earlier call in the scenario was surveyed and stored."""
    def check(result):
        problems = []
        offers = [e for e in result["events"] if e["event"] == "offered"]
        if len(offers) != (1 if surveyed_first else 0):
            problems.append(f"survey offered {len(offers)} times, expected {1 if surveyed_first else 0}")
        if len(result["sessions"]) != (1 if surveyed_first else 0):
            problems.append(f"{len(result['sessions'])} sessions stored")
        changed = {r: n for r, n in result["skips"].items() if n}
        if changed != {reason: 1}:
            problems.append(f"skip counts changed by {changed}, expected {{{reason!r}: 1}}")
        return problems
    return check


class survey_rules:
    """Changes the active survey's rules through the page for the length of a scenario."""

    def __init__(self, **rules):
        self.rules = {k: str(v) for k, v in rules.items()}

    def __enter__(self):
        self.id = active_survey_id()
        row = db_rows(f"SELECT {', '.join(self.rules)} FROM m_Surveys WHERE id = {self.id}")[0]
        self.saved = {k: "" if v is None else str(v) for k, v in row.items()}
        page_post("saveSurvey", {"id": self.id, "fields": json.dumps(self.rules)})
        pause(6)  # dialplan reload
        return self

    def __exit__(self, *exc):
        page_post("saveSurvey", {"id": self.id, "fields": json.dumps(self.saved)})
        docker("exec", PBX_CONTAINER, "asterisk", "-rx", "database deltree call-feedback-cap", capture=True)
        pause(6)
        return False


class module_settings:
    """Changes module settings through the page for the length of a scenario."""

    def __init__(self, **settings):
        self.settings = settings

    def __enter__(self):
        rows = db_rows("SELECT name, value FROM m_Settings")
        stored = {r["name"]: r["value"] for r in rows}
        self.saved = {k: (stored.get(k) or "").replace(",", " ") for k in self.settings}
        page_post("saveSettings", {"settings": json.dumps(self.settings)})
        return self

    def __exit__(self, *exc):
        page_post("saveSettings", {"settings": json.dumps(self.saved)})
        return False


class excluded_number:
    """Adds a number to the exclusion list through the page for the length of a scenario."""

    def __init__(self, number):
        self.number = number

    def __enter__(self):
        page_post("addExclusions", {"numbers": self.number, "note": "CallSim probe"})
        pause(6)
        return self

    def __exit__(self, *exc):
        for row in db_rows(f"SELECT id FROM m_Exclusions WHERE number = '{self.number}'"):
            page_post("removeExclusion", {"id": row["id"]})
        pause(6)
        return False


@scenario("Inbound to the direct DID, 301 answers after 2 s, talks 6 s and hangs up; the caller "
          "presses 4, then 1 and 0 (NPS 10).", surveyed("in", {"1": "4", "2": "10"}, 6, "301"))
def in_direct(lines):
    c, a = lines["carrier"], lines["301"]
    c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(6); a.hangup()
    pause(1.5); c.keys("4"); pause(1.5); c.keys("10"); pause(4); safe_hangup(c)


@scenario("Inbound to the IVR, caller presses 1 for the queue, 302 answers after 3 s, talks 6 s and "
          "hangs up; the caller presses 5, then a lone 1 (NPS 1, after the digit timeout).",
          surveyed("in", {"1": "5", "2": "1"}, 6, "302", queue="3100"))
def in_queue(lines):
    c, q1 = lines["carrier"], lines["302"]
    c.dial(DID_MAIN); c.established(); pause(2); c.keys("1"); q1.ringing(); pause(3); q1.answer()
    pause(6); q1.hangup(); pause(1.5); c.keys("5"); pause(1.5); c.keys("1"); pause(5); safe_hangup(c)


@scenario("Inbound to the IVR, no key: the IVR times out to the queue, 303 answers, talks 6 s and "
          "hangs up; the caller presses 3 and then nothing (NPS timeout).",
          surveyed("in", {"1": "3"}, 6, "303", status="started", queue="3100"))
def in_ivr_timeout_queue(lines):
    c, q2 = lines["carrier"], lines["303"]
    c.dial(DID_MAIN); c.established(); q2.ringing(30); pause(2); q2.answer(); pause(6); q2.hangup()
    pause(1.5); c.keys("3"); pause(10); safe_hangup(c)


@scenario("Inbound to 301, answered; 301 hangs up after 1 s of talk, under the 4 s minimum. Not "
          "surveyed; counted as too short.", skipped("too_short"))
def in_too_short(lines):
    c, a = lines["carrier"], lines["301"]
    c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(1); a.hangup()
    pause(4); safe_hangup(c)


@scenario("Inbound to 301, answered, 6 s talk; after 301 hangs up the caller presses 2 for the first "
          "question and hangs up during the second. The first answer is kept.",
          surveyed("in", {"1": "2"}, 6, "301", status="started", follow_up=True))
def in_partial(lines):
    c, a = lines["carrier"], lines["301"]
    c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(6); a.hangup()
    pause(1.5); c.keys("2"); pause(1.5); safe_hangup(c)


@scenario("Inbound to 301 from a number on the exclusion list (added through the page), 6 s talk, "
          "301 hangs up. Not surveyed; counted as excluded.", skipped("excluded"))
def in_excluded(lines):
    c, a = lines["carrier"], lines["301"]
    with excluded_number(CALLER):
        c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(6); a.hangup()
        pause(4); safe_hangup(c)


@scenario("With a 1-day frequency cap (set through the page), the same number calls twice: the first "
          "call is surveyed, the second is not and is counted as capped.", skipped("capped", surveyed_first=True))
def in_capped(lines):
    c, a = lines["carrier"], lines["301"]
    with survey_rules(frequency_cap_days=1):
        for keys in (True, False):
            c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(6); a.hangup()
            if keys:
                pause(1.5); c.keys("4"); pause(1.5); c.keys("9"); pause(4)
            else:
                pause(4)
            safe_hangup(c); pause(3)


def hours_excluding_now():
    """A two-hour survey window that starts an hour from now (PBX time), so now is outside it."""
    out = docker("exec", PBX_CONTAINER, "date", "+%H", capture=True).strip()
    hour = int(out)
    return f"{(hour + 1) % 24:02d}:00", f"{(hour + 3) % 24:02d}:00"


@scenario("With survey hours set (through the page) to a window that excludes now, an answered "
          "inbound call is not surveyed and is counted as outside hours.", skipped("outside_hours"))
def in_outside_hours(lines):
    c, a = lines["carrier"], lines["301"]
    start, end = hours_excluding_now()
    with survey_rules(hours_start=start, hours_end=end):
        c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(6); a.hangup()
        pause(4); safe_hangup(c)


def called_back(result):
    problems = []
    if result.get("refused") != "cf_CallBackTypeNumber":
        problems.append(f"without a safe prefix the page answered {result.get('refused')!r}, expected cf_CallBackTypeNumber")
    rows = db_rows(f"SELECT number, extension, confirmation FROM m_CallBacks WHERE followup_id = {int(result.get('case', 0))}")
    if [(r["number"], r["extension"], r["confirmation"]) for r in rows] != [(CALLER, "301", "listed")]:
        problems.append(f"logged call-backs {rows}, expected one to {CALLER} from 301")
    status = db_rows(f"SELECT status FROM m_FollowUps WHERE id = {int(result.get('case', 0))}")
    if not status or status[0]["status"] != "contacted":
        problems.append(f"follow-up status {status}, expected contacted")
    if not result.get("customer_rang"):
        problems.append("the customer's line never rang")
    if first(result["events"], "offered"):
        problems.append("the call-back itself was surveyed")
    return problems


CALL_BACK_STATE = {}


@scenario("Follow-up call-back: without a safe prefix the page refuses a one-click call-back; with "
          "the carrier's prefix listed it rings 301, and once 301 answers it calls the customer "
          "through the outbound route. The call-back is logged and the case marked contacted.", called_back)
def follow_up_call_back(lines):
    a, c = lines["301"], lines["carrier"]
    cases = db_rows("SELECT f.id FROM m_FollowUps f JOIN m_SurveySessions s ON s.id = f.session_id "
                    f"WHERE s.caller_num = '{CALLER}' AND f.status = 'open' ORDER BY f.id DESC LIMIT 1")
    if not cases:
        raise RuntimeError("No open follow-up for the CallSim caller; run in_partial first")
    case = int(cases[0]["id"])
    CALL_BACK_STATE.update(case=case, refused=None, customer_rang=False)
    with module_settings(callback_prefixes=""):
        try:
            page_post("callBack", {"id": case, "extension": "301", "typed": ""})
        except RuntimeError as e:
            CALL_BACK_STATE["refused"] = str(e).split(": ", 1)[-1]
    with module_settings(callback_prefixes=OUTBOUND_PREFIX):
        page_post("callBack", {"id": case, "extension": "301", "typed": ""})
        a.ringing(); pause(1); a.answer(); c.ringing(); CALL_BACK_STATE["customer_rang"] = True
        pause(1); c.answer(); pause(3); safe_hangup(a); pause(2); safe_hangup(c)


@scenario("Inbound to 301, answered; the caller hangs up first. Nothing can be surveyed.",
          not_surveyed)
def in_caller_hangs_up(lines):
    c, a = lines["carrier"], lines["301"]
    c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(4); c.hangup(); a.closed()


@scenario("Inbound to 301, which rejects the call. Never surveyed.", not_surveyed)
def in_rejected(lines):
    c, a = lines["carrier"], lines["301"]
    c.dial(DID_DIRECT); a.ringing(); pause(2); a.hangup(); pause(8); safe_hangup(c)


@scenario("Inbound to the IVR, caller presses 3 for voicemail, records 3 s and presses #; the "
          "voicemail ends the call. No agent: never surveyed.", not_surveyed)
def in_ivr_voicemail(lines):
    c = lines["carrier"]
    c.dial(DID_MAIN); c.established(); pause(2); c.keys("3"); pause(8); c.keys("#"); pause(8)
    safe_hangup(c)


@scenario("Inbound to the IVR with an extra action 4 -> hangup; the caller presses 4. No agent: "
          "never surveyed (the core marks this call answered).", not_surveyed)
def in_ivr_hangup(lines):
    c = lines["carrier"]
    with ivr_action("4", "hangup"):
        c.dial(DID_MAIN); c.established(); pause(2); c.keys("4"); pause(8); safe_hangup(c)


@scenario("Inbound to 301, answered; 301 blind-transfers to 302 (SIP REFER), 302 answers, talks 5 s "
          "and hangs up. Expected: the transfer leaves the route, so no survey.", not_surveyed)
def in_blind_transfer(lines):
    c, a, b = lines["carrier"], lines["301"], lines["302"]
    c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(3)
    a.transfer("302"); b.ringing(); pause(2); b.answer(); pause(5); b.hangup(); pause(6); safe_hangup(c)


@scenario("Inbound to 301, answered; 301 attended-transfers (##) to 302, then hangs up to complete "
          "it; 302 talks 5 s and hangs up; the caller presses 2 and 7. Talk time counts from the "
          "first answer (301), about 15 s.",
          surveyed("in", {"1": "2", "2": "7"}, 15, "301", follow_up=True))
def in_attended_transfer(lines):
    c, a, b = lines["carrier"], lines["301"], lines["302"]
    c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(3)
    a.keys("##302"); b.ringing(); pause(2); b.answer(); pause(3); a.hangup(); pause(5); b.hangup()
    pause(1.5); c.keys("2"); pause(1.5); c.keys("7"); pause(4); safe_hangup(c)


def tested(result):
    problems = []
    if not first(result["events"], "test_entry"):
        problems.append("the test call did not reach the survey")
    sessions = result["sessions"]
    if len(sessions) != 1:
        return problems + [f"{len(sessions)} stored sessions, expected 1"]
    row = sessions[0]
    if row.get("is_test") != 1 or row.get("caller_num") != "301":
        problems.append(f"stored is_test={row.get('is_test')} caller_num={row.get('caller_num')}, expected 1 and 301")
    if row["answers"] != {"1": "4", "2": "9"}:
        problems.append(f"stored answers {row['answers']}, expected 1: 4, 2: 9")
    return problems


@scenario("Test call from the survey editor: the page rings 301, 301 answers and presses 4, then 9. "
          "Stored as a test session, with answers, and never sent for CDR attribution.", tested)
def editor_test_call(lines):
    a = lines["301"]
    page_post("testCall", {"id": active_survey_id(), "extension": "301"})
    a.ringing(); pause(1); a.answer(); pause(1.5); a.keys("4"); pause(1.5); a.keys("9"); pause(4); safe_hangup(a)


@scenario("Outbound 301 -> carrier, answered after 2 s, 6 s talk, 301 hangs up; the customer "
          "presses 3, then 0 (NPS 0).", surveyed("out", {"1": "3", "2": "0"}, 6, "301"))
def out_answered(lines):
    a, c = lines["301"], lines["carrier"]
    a.dial(OUTBOUND_NUMBER); c.ringing(); pause(2); c.answer(); a.established(); pause(6); a.hangup()
    pause(1.5); c.keys("3"); pause(1.5); c.keys("0"); pause(4); safe_hangup(c)


@scenario("Outbound 301 -> carrier, answered; the customer hangs up first. Never surveyed, and the "
          "agent hears nothing.", not_surveyed)
def out_customer_hangs_up(lines):
    a, c = lines["301"], lines["carrier"]
    a.dial(OUTBOUND_NUMBER); c.ringing(); pause(2); c.answer(); a.established(); pause(4); c.hangup()
    pause(4); safe_hangup(a)


@scenario("Outbound 301 -> carrier, rings 6 s unanswered, 301 hangs up. Never surveyed.", not_surveyed)
def out_no_answer(lines):
    a, c = lines["301"], lines["carrier"]
    a.dial(OUTBOUND_NUMBER); c.ringing(); pause(6); a.hangup(); pause(4); safe_hangup(c)


# --------------------------------------------------------------------------- runner

def run(names):
    chosen = [s for s in SCENARIOS if not names or s["name"] in names]
    unknown = set(names) - {s["name"] for s in SCENARIOS}
    if unknown:
        sys.exit(f"Unknown scenario(s): {', '.join(sorted(unknown))}")
    RESULTS_DIR.mkdir(exist_ok=True)
    summary = []
    with callsim_routes_in_hours():
        for s in chosen:
            print(f"{s['name']}: {s['description']}")
            lines = {n: Line(n) for n in [*callsim.PHONES, "carrier"]}
            since = log_size()
            skips_before = skip_totals()
            error = None
            try:
                s["run"](lines)
            except Exception as e:  # noqa: BLE001 - report and carry on
                error = str(e)
                safe_hangup(*lines.values())
            events = wait_quiet(since)
            ids = linkedids(events)
            expects_session = first(events, "offered") is not None
            result = {
                **(CALL_BACK_STATE if s["name"] == "follow_up_call_back" else {}),
                "events": events,
                "sessions": wait_for_sessions(ids, expects_session, ATTRIBUTION_TIMEOUT if expects_session else 5),
                "skips": {r: n - skips_before[r] for r, n in skip_totals().items()},
            }
            problems = ([f"scenario error: {error}"] if error else []) + s["expect"](result)
            for e in events:
                print("   ", e["event"], {k: v for k, v in e.items() if k not in ("event", "time")})
            for row in result["sessions"]:
                print("    stored", {k: row[k] for k in ("status", "answers", "agent", "queue", "talk_seconds",
                                                       "cdr_talk_seconds", "excluded")})
            print("  PASS" if not problems else "  FAIL: " + "; ".join(problems))
            (RESULTS_DIR / f"{s['name']}.json").write_text(json.dumps(
                {"scenario": s["name"], "description": s["description"], "problems": problems,
                 **result}, indent=2) + "\n")
            summary.append((s["name"], problems))
            time.sleep(3)
    print("\nSummary:")
    for name, problems in summary:
        print(f"  {'PASS' if not problems else 'FAIL'}  {name}" + (f": {'; '.join(problems)}" if problems else ""))


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else "help"
    if cmd == "run":
        run(sys.argv[2:])
    elif cmd == "list":
        for s in SCENARIOS:
            print(f"{s['name']:26} {s['description']}")
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
