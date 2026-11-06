# Call Feedback for MikoPBX

A free MikoPBX module that keeps the customer on the line when the agent hangs up and runs a short keypad survey: one headline score (CSAT 1–5 or NPS 0–10) and an optional "was your issue resolved?" question. Results are tied to the call and shown on admin-only dashboards with a follow-up list for unhappy callers.

> **Status: in development, not ready for use.** The current build is a call-path probe: it plays a test survey (built-in beep prompts) on every answered inbound and outbound call and logs each decision to Asterisk's notice log. Install it only on a test PBX. There are no settings, stored results or pages yet.

## Requirements

- MikoPBX 2026.6.34 or later.

## How it works

The module adds its survey through MikoPBX's module dialplan hooks; it doesn't edit custom dialplan files.

- **Inbound:** MikoPBX's inbound routes dial with Asterisk's `g` option, so the caller's channel continues after the agent hangs up. The module's after-dial hook enters the survey with `Gosub` and returns, so any custom after-dial dialplan still runs.
- **Outbound:** the module appends `F(...)` to the outbound route's dial options, which moves the customer into the survey when the agent hangs up.
- **Only calls an agent answered are surveyed.** The module marks when an agent's phone answers (inbound) or hands the agent's talk time to the survey (outbound), so calls that only reached an IVR, voicemail or a hangup action are skipped, and talk time excludes time on hold.
- **Not surveyed:** callers who hang up first, and calls that were blind-transferred (the transfer moves the caller out of the inbound route).

## Testing

`tools/callsim/` places real SIP calls through a disposable MikoPBX test instance with softphones and a fake carrier. `probe.py` runs the survey call-path scenarios against the probe build. See [tools/callsim/README.md](tools/callsim/README.md).

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
