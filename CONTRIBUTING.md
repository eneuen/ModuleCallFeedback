# Contributing to Call Feedback

Thanks for your interest. This guide covers how to report problems, how to set up the code, the checks a change must pass, where things live, and how a release is made.

## Reporting problems and suggesting changes

- **Bugs and ideas:** open an issue. For a bug, say which MikoPBX version you run, what you did, what you expected and what happened.
- **Security problems:** please don't open a public issue; follow [SECURITY.md](SECURITY.md).
- **Pull requests:** welcome. Keep each one to a single change, add or update tests where the code allows it, and update [CHANGELOG.md](CHANGELOG.md) under `## [Unreleased]`. CI must pass.

## Development

Unit tests cover the pure-PHP parts (no PBX or Phalcon needed) and run with PHP 8.4 or later:

```bash
composer install
composer test
composer lint
composer check-banned
```

`check-banned` fails if shipped code uses shell or code execution or `unserialize`, or if the browser script or views use `eval`, HTML injection, inline scripts or inline event handlers. Caller IDs come from outside callers and survey names from admins, so the page only ever inserts them as text.

Static analysis runs PHPStan against the MikoPBX core release the module targets. The core and its dependencies are large (about 240 MB), so they live in their own folder:

```bash
composer install -d tools/phpstan --ignore-platform-req='ext-*'
composer analyse
```

The browser assets are built from `public/assets/js/src/` and `build/echarts-entry.js`, and the built files are committed:

```bash
npm ci
npm run build
```

CI (`.github/workflows/ci.yml`) runs all of the above on every push, runs `npm audit`, and checks that the committed bundles match a fresh build.

Keep code that needs no core classes (the hand-off validation, the dialplan builder, the store, results, scores, follow-ups, privacy) free of core and Phalcon dependencies, so it stays unit-testable here.

Code that needs the MikoPBX core (the hooks, the worker, the AGI script, the controller) is tested on a MikoPBX 2026.6.34 instance, such as the official Docker image `ghcr.io/mikopbx/mikopbx:2026.6.34`. [tools/callsim](tools/callsim/README.md) places real SIP calls through such a test PBX and checks the survey end to end; run its probe scenarios before a release.

## How a survey runs

The module adds its survey through MikoPBX's module dialplan hooks; it doesn't edit custom dialplan files. With no active survey it adds nothing to any call.

- **Inbound:** MikoPBX's inbound routes dial with Asterisk's `g` option, so the caller's channel continues after the agent hangs up. The module's after-dial hook enters the survey with `Gosub` and returns, so any custom after-dial dialplan still runs.
- **Outbound:** on targeted routes, the module appends `F(...)` to the dial options, which moves the customer into the survey when the agent hangs up.
- **Only calls an agent answered are surveyed.** The module marks when an agent's phone answers (inbound) or hands the agent's talk time to the survey (outbound), so calls that only reached an IVR, voicemail or a hangup action are skipped, and talk time excludes time waiting in a queue.
- **Not surveyed:** callers who hang up first, and blind-transferred calls (the transfer moves the caller out of the inbound route). Calls a rule skips (too short, outside survey hours, excluded, asked recently, not sampled, too many surveys at once) are counted per day, survey version, route and reason.
- **Recording results:** answers are kept in channel variables during the survey. When the customer's channel ends, a hangup handler runs the module's AGI script once. It checks every value against a fixed pattern and passes the record to the module's worker over the PBX's internal Beanstalk queue. The worker checks it again, keeps only answers that are on the question's scale, and stores the session, so a caller who hangs up after the first question still counts.
- **Attribution:** once the call is in the CDR, the worker fills in the answering agent, the queue and the call's real talk time from its CDR legs (the same leg-stitching logic as the Call Insights module). A session under the minimum talk time is kept but left out of scores, and a session whose call never appears in the CDR is discarded. Only then does a low score open a follow-up and send the alert.
- **Limits:** `TIMEOUT(absolute)` ends every survey within 3 minutes, and a `GROUP_COUNT` check caps the surveys running at once.
- **Built-in prompts:** `Sounds/<language>/<key>.wav` (8 kHz, 16-bit mono WAV) are copied by the core into Asterisk's sound folder for that language as `modulecallfeedback-<key>` when the module is enabled, converted to its other formats, and removed when it is disabled. The language folders are the codes a survey sets with `CHANNEL(language)`, so the dialplan plays `modulecallfeedback-<key>` with no path and Asterisk picks the survey's language. A survey stores them as negative prompt IDs (`BuiltInPrompts::IDS`); the PBX's sound-file IDs are positive. To add one, add its key to `BuiltInPrompts::IDS`, a recording in each language folder, and a label in the page script and `Messages/`.

New or re-recorded prompts are converted from the original recording (any format ffmpeg reads) the same way as the shipped ones, so every prompt has the same level and a short pad at each end:

```bash
ffmpeg -i original.mp3 -af "highpass=f=90,loudnorm=I=-19:TP=-3:LRA=11,adelay=150,apad=pad_dur=0.25,aresample=8000" \
  -ac 1 -ar 8000 -c:a pcm_s16le -map_metadata -1 -fflags +bitexact -flags:a +bitexact Sounds/en-en/<key>.wav
```

The recordings are CC0 (`Sounds/LICENSE`); a contribution to them must be too.

The unit tests run against SQLite files built from the module's model annotations and from calls recorded on a real PBX (`tests/fixtures/cdr`). [tools/callsim](tools/callsim/README.md) places real SIP calls through a disposable MikoPBX test instance with softphones and a fake carrier; its `probe.py` runs the survey scenarios (inbound, outbound, transfers, rules, test calls, call-backs) and checks both the dialplan's decisions and what the worker stored.

## Changing the database

The core creates the module's tables from the model annotations in `Models/` on install, and adds new columns on update. It doesn't reliably change an existing table's indexes or move data, so a change like that goes into `Lib/Migrations.php` as the next numbered step, with `LATEST` raised to match and a unit test that upgrades a database from the previous version. The installer runs the steps after the core has restored the kept database, each once, in order and in a transaction; a failed step stops the install. A new database starts at `LATEST`. Version 1 is the first release's schema.

Before a release, run the load benchmark ([tools/benchmark](tools/benchmark/README.md)) if the results queries changed.

## Layout

| Path | What it holds |
| --- | --- |
| `module.json` | Module manifest: ID, version, minimum MikoPBX version |
| `Lib/CallFeedbackConf.php` | Hooks the core calls: dialplan, the worker. No REST routes, by design |
| `Lib/DialplanBuilder.php` | Builds every line of dialplan the module adds, from the stored surveys; only validated values reach it |
| `agi-bin/call_feedback_end.php` | The survey's end-of-call hand-off: validates the channel variables and queues the record |
| `Lib/Handoff.php` | The end-of-call record and its validation, shared by the AGI script and the worker |
| `Lib/WorkerCallFeedback.php` | Background worker: stores records, attributes them from the CDR, follow-ups and alerts, test calls and call-backs, nightly retention, summary emails |
| `Lib/FeedbackStore.php` | Writes sessions, answers and skip counts to the module database |
| `Lib/Attribution.php`, `Lib/CallIndexer.php`, `Lib/CdrReader.php` | Agent, queue and talk time from the call's CDR legs. `CallIndexer` is shared with the Call Insights module, with its tests and fixtures |
| `Lib/SurveyEditor.php`, `Lib/SurveyRepository.php` | Survey versions, questions, rules and targets: every change validated, activation checks |
| `Lib/BuiltInPrompts.php`, `Sounds/` | The module's own prompts: their IDs and names, and the recordings in `Sounds/en-en` and `Sounds/ru-ru` |
| `Lib/SurveyTemplates.php`, `Lib/ExampleSurvey.php` | The templates offered under New Survey, and the example survey (the CSAT template) a new install starts with |
| `Lib/ScalePresets.php`, `Lib/Scores.php` | The answer scales and how a score is worked out |
| `Lib/Results.php` | Overview, Breakdowns and Survey health |
| `Lib/FollowUps.php`, `Lib/CallBacks.php`, `Lib/AlertMessage.php`, `Lib/SummaryMessage.php` | Detractor cases, call-backs with their safeguards, alert and summary emails |
| `Lib/Privacy.php` | Retention of callers' numbers and "delete all data for a number" |
| `Lib/Migrations.php` | Numbered changes to the module database between versions, and the version it has reached |
| `Lib/ModuleSettings.php`, `Lib/ConfigOptions.php` | Module settings; what the editor can choose from the PBX configuration |
| `Lib/AccessPolicy.php` | Who may use the module, and what a write request must carry |
| `Models/` | Tables in the module's database |
| `Setup/PbxExtensionSetup.php` | Installer: sidebar entry, and on uninstall the AGI link and astdb keys the core leaves behind |
| `App/Controllers/`, `App/Views/` | The module page, its JSON actions and the access-denied response. Reads are AJAX GETs; writes must be AJAX POSTs with a Bearer token |
| `public/assets/` | Page script and styles; `js/vendor/` holds the ECharts bundle and its licence |
| `Messages/` | Interface text (English and Russian) |
| `build/`, `package.json` | Builds the browser assets with esbuild, and the release zip (`build/package.sh`) |
| `tests/Unit/` | PHPUnit tests that run without a PBX |
| `docs/screenshots/` | The README's screenshots, taken on a test PBX with simulated calls |
| `tests/fixtures/` | CDR legs recorded from real calls, and the production `cdr_general` schema |
| `tools/callsim/` | Real-call test harness and the survey probe scenarios |
| `tools/check-banned.php` | Fails the build if shipped code uses a banned function |
| `tools/benchmark/` | Load benchmark: a year of synthetic surveys, timed with the page's own queries |
| `tools/phpstan/` | PHPStan setup against the MikoPBX core |
| `.gitattributes` | Lists the development files that are left out of every release |

## Packaging a release

This section is for maintainers.

What ships is everything except the development files listed as `export-ignore` in `.gitattributes`: tests, tools, build scripts, script sources, dependency manifests, CI files and the README's screenshots. That one list applies to every archive git makes, including the release zip and GitHub's "Source code" downloads. Those downloads are for developers only, because `module.json` keeps its `%ModuleVersion%` placeholder until a release is packaged. To work on the module, clone the repository.

To release a version:

1. In `CHANGELOG.md`, move the entries under `## [Unreleased]` into a new section for the version and date, for example `## [1.0.0] - 2026-12-01`, leave `## [Unreleased]` empty above it, and commit.
2. Tag that commit with the version (`1.0.0` or `v1.0.0`) and push the tag.
3. The Release workflow runs every CI check on the tagged commit, builds `ModuleCallFeedback-<version>.zip` and its SHA-256 checksum with `build/package.sh`, and attaches them to a **draft** GitHub release, with that version's CHANGELOG section as the notes.
4. Review the draft and publish it.

To build a package locally, for testing:

```bash
build/package.sh 1.0.0-rc.1
```

This writes `dist/ModuleCallFeedback-1.0.0-rc.1.zip` and its checksum, built from the committed files of `HEAD` (or a commit given as the second argument), with the version filled into `module.json`. The same commit always gives the same zip.

## License

By contributing, you agree that your contributions are licensed under the project's license, GPL-3.0-or-later.
