# Changelog

All notable changes to this module are listed here. Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

Added:

- **Configurable surveys.** The built-in test survey is replaced by surveys set up on the module's page (Modules → Call Feedback, administrators only).
  - Each survey has versions (draft, active, archived) and 1–3 questions on fixed scales: CSAT 1–5, NPS 0–10, CES 1–5 (asked as "how easy was it") and yes/no. Question 1 is the headline score. Prompts are chosen from the PBX's sound files, by ID; timeouts, retries and an invalid-key prompt are set per survey.
  - An active version's questions and prompts are fixed so results stay comparable; editing them means a new version. Its rules and routes can still change.
  - Rules: minimum talk time, the share of calls to survey, a frequency cap per number (astdb), a list of excluded numbers and a limit on surveys running at once (GROUP_COUNT). Skipped calls are counted per day, route and reason.
  - Targeting by incoming and outgoing route, matched when the agent hangs up (provider and DID, most specific first). One active survey per route. With no active survey the module adds no dialplan at all.
  - Activation checks that every prompt plays, a test call was made on the version, the routes are free, the PBX's default incoming action doesn't play a sound file (which removes the after-dial hook from every route), and no other post-call survey module is enabled.
- **Test call.** The survey editor rings an extension and plays the version without the rules; the session is kept apart from results. The page asks the module's worker to place the call over its Beanstalk queue, because the web process has no AMI connection.
- **Results.** Overview (headline score with its change against the previous period, average, response and completion rates, resolution rate, score over time, answer distribution, calls not asked and why) and Breakdowns (by agent, queue, line, direction, hour, weekday). Scores are per version, need a minimum number of responses (default 20) before they are shown, and leave out test calls and calls the CDR shows were too short. Charts use a tree-shaken Apache ECharts 6.1.0.
- **Follow-ups and alerts.** A headline score at or below the detractor threshold (CSAT ≤ 2, NPS ≤ 6, CES ≤ 2 by default) opens a follow-up once the call is confirmed in the CDR, and emails the chosen recipients through the PBX's mail settings. The email names the survey, score and agent and points to the page; it never carries the caller's number. The Follow-ups tab lists cases with a status, a note and the time to resolve.
- **Settings:** alert recipients, detractor thresholds, minimum sample, the concurrent-survey limit and the exclusion list.
- **Interface in English and Russian.**
- **Tests:** 206 unit tests (no PBX needed) and 17 CallSim scenarios, including the test call, an excluded number and the frequency cap set through the page, and follow-ups. All pass on MikoPBX 2026.6.34.

Changed:

- The dialplan's probe lines moved from Asterisk's notice log to its verbose log, so a production log isn't filled with them.
- Admin-only access, CSRF protection for writes and the page's JSON conventions follow the Call Insights module.

Earlier in this release:

- **Stored results.** Each surveyed call is now recorded in the module's database: a session (direction, route, DID, caller number, measured talk time, status) with its answers, so a caller who hangs up after the first question still counts. Calls under the minimum talk time (4 s for the built-in test survey) are not asked and are counted per day, route and reason instead.
  - The survey's hangup handler runs one AGI script, `call_feedback_end.php`, after the customer has gone. It validates every channel variable against a fixed pattern and publishes the record to the module's Beanstalk queue; caller-controlled text such as `123;Hangup()` is rejected.
  - A new worker (`WorkerCallFeedback`) validates each record again, keeps only answers inside the question's range, works out the status from the answers kept, and ignores a second record for the same call.
  - Every 30 seconds the worker attributes finished sessions from the CDR: agent, queue and the call's real talk time, using `CallIndexer` from the Call Insights module. Sessions under the minimum talk time are marked excluded; sessions whose call never appears in the CDR within 10 minutes are discarded.
  - Uninstalling removes the AGI link and the module's astdb keys, which the core leaves behind.
- **Unit tests** (PHPUnit, no PBX needed): the record validation, the store, attribution against CDR legs recorded from real calls, and the dialplan's safety rules. `CallIndexer`'s own tests and recorded fixtures come with it from Call Insights.
- **CallSim probe:** every scenario now also checks the stored session, answers, attribution and skip counts; two new scenarios cover a call that is too short and a caller who hangs up mid-survey. All 14 pass on MikoPBX 2026.6.34. The hand-off costs about 17 ms per call on the test PBX; 50 at once completed in 0.23 s with none lost.

- **Call-path probe build.** The module's first code: dialplan hooks that play a two-question test survey after every answered inbound and outbound call and log each decision, to prove the call path before settings and storage are built.
  - Inbound: the after-dial hook enters the survey with `Gosub` and returns, so custom after-dial dialplan keeps running. Outbound: `F(...)` is appended to the route's dial options.
  - Agent answer and talk time: a hangup handler on each agent leg in `[internal-users]` marks when an agent answered (inbound); a handler on the agent's channel hands its talk time to the survey through astdb when the agent hangs up first (outbound). Calls with no agent answer, such as an IVR option that hangs up, are skipped.
  - Keys: one key for CSAT; 0–10 for NPS, with "1 then 0" for 10 and a 2-second digit timeout for a lone 1.
- **CallSim** (from the Call Insights module): softphones and a fake carrier for real test calls, plus `probe.py`, twelve survey scenarios checked against the probe log. All pass on MikoPBX 2026.6.34.
