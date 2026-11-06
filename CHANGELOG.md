# Changelog

All notable changes to this module are listed here. Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

Added:

- **Call-path probe build.** The module's first code: dialplan hooks that play a two-question test survey after every answered inbound and outbound call and log each decision, to prove the call path before settings and storage are built.
  - Inbound: the after-dial hook enters the survey with `Gosub` and returns, so custom after-dial dialplan keeps running. Outbound: `F(...)` is appended to the route's dial options.
  - Agent answer and talk time: a hangup handler on each agent leg in `[internal-users]` marks when an agent answered (inbound); a handler on the agent's channel hands its talk time to the survey through astdb when the agent hangs up first (outbound). Calls with no agent answer, such as an IVR option that hangs up, are skipped.
  - Keys: one key for CSAT; 0–10 for NPS, with "1 then 0" for 10 and a 2-second digit timeout for a lone 1.
- **CallSim** (from the Call Insights module): softphones and a fake carrier for real test calls, plus `probe.py`, twelve survey scenarios checked against the probe log. All pass on MikoPBX 2026.6.34.
