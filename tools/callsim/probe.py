#!/usr/bin/env python3
"""Survey probe: real calls through the test PBX, checked against the module's log and database.

The module's dialplan writes a "CFPROBE event=... key=value" line to
Asterisk's notice log at every survey decision. Each scenario here places a
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

import callsim
from callsim import (CALLER, DID_DIRECT, DID_MAIN, IVR, OUTBOUND_NUMBER, PBX_CONTAINER,
                     Line, Pbx, callsim_routes_in_hours, docker, pause)

HERE = Path(__file__).resolve().parent
RESULTS_DIR = HERE / "probe-results"
MESSAGES_LOG = "/storage/usbdisk1/mikopbx/log/asterisk/messages"
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


def skip_total(reason):
    rows = db_rows(f"SELECT COALESCE(SUM(count), 0) AS n FROM m_SkipCounts WHERE reason = '{reason}'")
    return int(rows[0]["n"]) if rows else 0


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


def surveyed(direction, answers, talk, agent, status="completed", queue=None):
    """Expectation: survey offered in `direction`, `answers` ({"1": "4", ...}) stored with
    `status`, dialplan talk time about `talk` s, and the CDR attributing it to `agent`."""
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
        return problems
    return check


def not_surveyed(result):
    problems = ["survey was offered"] if first(result["events"], "offered") else []
    if result["sessions"]:
        problems.append(f"{len(result['sessions'])} session(s) stored")
    return problems


def skipped_too_short(result):
    problems = ["survey was offered"] if first(result["events"], "offered") else []
    if result["sessions"]:
        problems.append("a session was stored")
    if result["skips"] != 1:
        problems.append(f"too-short count went up by {result['skips']}, expected 1")
    return problems


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
          "surveyed; counted as too short.", skipped_too_short)
def in_too_short(lines):
    c, a = lines["carrier"], lines["301"]
    c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(1); a.hangup()
    pause(4); safe_hangup(c)


@scenario("Inbound to 301, answered, 6 s talk; after 301 hangs up the caller presses 2 for the first "
          "question and hangs up during the second. The first answer is kept.",
          surveyed("in", {"1": "2"}, 6, "301", status="started"))
def in_partial(lines):
    c, a = lines["carrier"], lines["301"]
    c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(6); a.hangup()
    pause(1.5); c.keys("2"); pause(1.5); safe_hangup(c)


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
          surveyed("in", {"1": "2", "2": "7"}, 15, "301"))
def in_attended_transfer(lines):
    c, a, b = lines["carrier"], lines["301"], lines["302"]
    c.dial(DID_DIRECT); a.ringing(); pause(2); a.answer(); c.established(); pause(3)
    a.keys("##302"); b.ringing(); pause(2); b.answer(); pause(3); a.hangup(); pause(5); b.hangup()
    pause(1.5); c.keys("2"); pause(1.5); c.keys("7"); pause(4); safe_hangup(c)


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
            skips_before = skip_total("too_short")
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
                "events": events,
                "sessions": wait_for_sessions(ids, expects_session, ATTRIBUTION_TIMEOUT if expects_session else 5),
                "skips": skip_total("too_short") - skips_before,
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
