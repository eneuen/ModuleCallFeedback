# Changelog

All notable changes to this module are listed here. Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

Added:

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
