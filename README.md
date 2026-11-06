# Call Feedback for MikoPBX

A free MikoPBX module that keeps the customer on the line when the agent hangs up and runs a short keypad survey: one headline score (CSAT 1–5 or NPS 0–10) and an optional "was your issue resolved?" question. Results are tied to the call and shown on admin-only dashboards with a follow-up list for unhappy callers.

> **Status: in development, not ready for use.** The current build plays a built-in test survey (beep prompts: one key for CSAT 1–5, then NPS 0–10) on every answered inbound and outbound call, stores the results in the module's database and logs each decision to Asterisk's notice log. Install it only on a test PBX. There are no settings or pages yet.

## Requirements

- MikoPBX 2026.6.34 or later.

## How it works

The module adds its survey through MikoPBX's module dialplan hooks; it doesn't edit custom dialplan files.

- **Inbound:** MikoPBX's inbound routes dial with Asterisk's `g` option, so the caller's channel continues after the agent hangs up. The module's after-dial hook enters the survey with `Gosub` and returns, so any custom after-dial dialplan still runs.
- **Outbound:** the module appends `F(...)` to the outbound route's dial options, which moves the customer into the survey when the agent hangs up.
- **Only calls an agent answered are surveyed.** The module marks when an agent's phone answers (inbound) or hands the agent's talk time to the survey (outbound), so calls that only reached an IVR, voicemail or a hangup action are skipped, and talk time excludes time on hold.
- **Not surveyed:** callers who hang up first, and calls that were blind-transferred (the transfer moves the caller out of the inbound route). Calls under the survey's minimum talk time are not asked and are counted per day, route and reason.
- **Recording results:** answers are kept in channel variables during the survey. When the customer's channel ends, a hangup handler runs the module's AGI script once. It checks every value against a fixed pattern and passes the record to the module's worker over the PBX's internal Beanstalk queue. The worker checks it again, keeps only answers inside each question's range, and stores the session, so a caller who hangs up after the first question still counts.
- **Attribution:** once the call is in the CDR, the worker fills in the answering agent, the queue and the call's real talk time from its CDR legs (the same leg-stitching logic as the Call Insights module). A session whose CDR talk time is under the minimum is kept but marked excluded from scores, and a session whose call never appears in the CDR is discarded.

## Testing

```bash
composer install
composer test
```

The unit tests need PHP 8.4 or later and no PBX: the CDR tests run against SQLite files built from recorded calls (`tests/fixtures/cdr`).

`tools/callsim/` places real SIP calls through a disposable MikoPBX test instance with softphones and a fake carrier. `probe.py` runs the survey scenarios and checks both the dialplan's decisions and the rows the worker stored. See [tools/callsim/README.md](tools/callsim/README.md).

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
