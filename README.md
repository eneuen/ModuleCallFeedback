# Call Feedback for MikoPBX

A free MikoPBX module that keeps the customer on the line when the agent hangs up and runs a short keypad survey: one headline score (CSAT 1–5, NPS 0–10 or CES 1–5) and up to two more questions, such as "was your issue resolved?". Results are tied to the call and shown to administrators with a follow-up list and email alerts for unhappy callers.

> **Status: in development, not released yet.** The survey, results and follow-ups work on MikoPBX 2026.6.34 and are tested with real calls, but the module is not yet ready for production use.

## Requirements

- MikoPBX 2026.6.34 or later.
- Prompts as custom sound files (upload them under Sound files; MIKO's Phrase Studio module can generate them).
- For alert emails: the PBX's mail settings, with email notifications switched on.

## Setting up a survey

Everything is under **Modules → Call Feedback**, for administrators only.

1. **Surveys → New survey.** Choose a name and the prompt language (English or Russian).
2. **Prompts and questions.** Choose the intro, thank-you and (optionally) invalid-key prompts, and up to three questions. Question 1 is the headline score; the others can be any scale, including yes/no. For NPS, the prompt must say "press 1 then 0 for 10".
3. **Who gets surveyed.** Minimum talk time (default 20 s), the days and hours the survey runs, the share of calls to survey, and how long to wait before asking the same number again (default 30 days).
4. **Runs on.** The incoming and outgoing routes the survey runs on. Only one active survey may run on a route.
5. **Test call.** Ring an extension and answer the survey as a caller would. A version needs a test call before it can go live.
6. **Activate.** Once active, the questions and prompts are fixed so results stay comparable; to change the wording, create a new version. Rules and routes can still change.

**Settings** holds the detractor thresholds (CSAT ≤ 2, NPS ≤ 6, CES ≤ 2 by default), alert recipients and a daily or weekly summary email, the number prefixes that may be called back in one click, the minimum responses before a score is shown, the limit on surveys running at once, the list of numbers never to survey, and "delete all data for a number".

## What it shows

- **Overview:** the headline score with its change against the previous period, the average, response and completion rates, the resolution rate, the score over time and the distribution of answers. Every score shows its response count, and scores with too few responses are not shown.
- **Breakdowns:** the score by agent, queue, line, direction, hour of day, weekday, time to answer or talk time, with small groups greyed out.
- **Survey health:** how many callers were offered the survey, answered each question and finished it, where they dropped off, and how many calls each rule left unasked.
- **Follow-ups:** every call with a low headline score, newest first, with the caller's number, agent and queue, a status (open, contacted, resolved) and a note. **Call back** rings an extension of your choice and, when it answers, calls the customer through the normal outgoing routes.

Results are per survey version: different wordings are never averaged together.

## How it works

The module adds its survey through MikoPBX's module dialplan hooks; it doesn't edit custom dialplan files. With no active survey it adds nothing to any call.

- **Inbound:** MikoPBX's inbound routes dial with Asterisk's `g` option, so the caller's channel continues after the agent hangs up. The module's after-dial hook enters the survey with `Gosub` and returns, so any custom after-dial dialplan still runs.
- **Outbound:** on targeted routes, the module appends `F(...)` to the dial options, which moves the customer into the survey when the agent hangs up.
- **Only calls an agent answered are surveyed.** The module marks when an agent's phone answers (inbound) or hands the agent's talk time to the survey (outbound), so calls that only reached an IVR, voicemail or a hangup action are skipped, and talk time excludes time on hold.
- **Not surveyed:** callers who hang up first, and blind-transferred calls (the transfer moves the caller out of the inbound route). Calls a rule skips (too short, excluded, asked recently, not sampled, too many surveys at once) are counted per day, route and reason.
- **Recording results:** answers are kept in channel variables during the survey. When the customer's channel ends, a hangup handler runs the module's AGI script once. It checks every value against a fixed pattern and passes the record to the module's worker over the PBX's internal Beanstalk queue. The worker checks it again, keeps only answers that are on the question's scale, and stores the session, so a caller who hangs up after the first question still counts.
- **Attribution:** once the call is in the CDR, the worker fills in the answering agent, the queue and the call's real talk time from its CDR legs (the same leg-stitching logic as the Call Insights module). A session under the minimum talk time is kept but left out of scores, and a session whose call never appears in the CDR is discarded. Only then does a low score open a follow-up and send the alert.

## Privacy and security

- Admin-only pages; writes need an AJAX POST with the admin's token, so a cross-site form can't change anything.
- Nothing typed by an admin or sent by a caller reaches the dialplan: only IDs, digits, fixed values and sound-file paths resolved by the PBX.
- No new AMI users, open ports or outbound connections. The survey contexts can't be dialled from a phone or a trunk.
- Alert and summary emails carry the survey, score, agent and a link to the page, never the caller's number.
- **Call-back safeguards:** a caller can fake an international or premium-rate number and wait to be called back. Only numbers starting with a prefix you list in Settings (none by default) can be called back in one click; any other number must be typed in to match. Call-backs go through the outgoing routes and their restrictions, and each one is logged with who placed it.
- **Retention:** callers' numbers are kept as long as the PBX keeps its call history (the call history's retention setting), then removed; scores stay. The "don't ask again" memory follows the same limit, so with a retention shorter than a survey's frequency cap, privacy wins. "Delete all data for a number" removes a caller's sessions, answers and follow-ups at once.
- A survey ends within 3 minutes whatever is pressed, and the number running at once is capped, so surveys can't tie up trunk channels.
- No audio is recorded.

NPS: Net Promoter, NPS and Net Promoter Score are registered trademarks of Bain & Company, Inc., Satmetrix Systems, Inc. and Fred Reichheld.

## Development

```bash
composer install
composer test        # unit tests: PHP 8.4 or later, no PBX needed
composer check-banned
npm ci
npm run build        # rebuilds public/assets/js from public/assets/js/src
```

The unit tests run against SQLite files built from the module's model annotations and from calls recorded on a real PBX (`tests/fixtures/cdr`).

`tools/callsim/` places real SIP calls through a disposable MikoPBX test instance with softphones and a fake carrier. `probe.py` runs the survey scenarios (inbound, outbound, transfers, rules, test calls, call-backs) and checks both the dialplan's decisions and what the worker stored. See [tools/callsim/README.md](tools/callsim/README.md).

See [CONTRIBUTING.md](CONTRIBUTING.md) for static analysis, the layout and how a release is packaged, and [SECURITY.md](SECURITY.md) to report a vulnerability.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE). The bundled Apache ECharts is Apache-2.0; its licence and notice are in `public/assets/js/vendor/`.
