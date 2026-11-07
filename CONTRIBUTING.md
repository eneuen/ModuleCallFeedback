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
| `Lib/ScalePresets.php`, `Lib/Scores.php` | The answer scales and how a score is worked out |
| `Lib/Results.php` | Overview, Breakdowns and Survey health |
| `Lib/FollowUps.php`, `Lib/CallBacks.php`, `Lib/AlertMessage.php`, `Lib/SummaryMessage.php` | Detractor cases, call-backs with their safeguards, alert and summary emails |
| `Lib/Privacy.php` | Retention of callers' numbers and "delete all data for a number" |
| `Lib/ModuleSettings.php`, `Lib/ConfigOptions.php` | Module settings; what the editor can choose from the PBX configuration |
| `Lib/AccessPolicy.php` | Who may use the module, and what a write request must carry |
| `Models/` | Tables in the module's database |
| `Setup/PbxExtensionSetup.php` | Installer: sidebar entry, and on uninstall the AGI link and astdb keys the core leaves behind |
| `App/Controllers/`, `App/Views/` | The module page, its JSON actions and the access-denied response. Reads are AJAX GETs; writes must be AJAX POSTs with a Bearer token |
| `public/assets/` | Page script and styles; `js/vendor/` holds the ECharts bundle and its licence |
| `Messages/` | Interface text (English and Russian) |
| `build/`, `package.json` | Builds the browser assets with esbuild, and the release zip (`build/package.sh`) |
| `tests/Unit/` | PHPUnit tests that run without a PBX |
| `tests/fixtures/` | CDR legs recorded from real calls, and the production `cdr_general` schema |
| `tools/callsim/` | Real-call test harness and the survey probe scenarios |
| `tools/check-banned.php` | Fails the build if shipped code uses a banned function |
| `tools/phpstan/` | PHPStan setup against the MikoPBX core |
| `.gitattributes` | Lists the development files that are left out of every release |

## Packaging a release

This section is for maintainers.

What ships is everything except the development files listed as `export-ignore` in `.gitattributes`: tests, tools, build scripts, script sources, dependency manifests and CI files. That one list applies to every archive git makes, including the release zip and GitHub's "Source code" downloads. Those downloads are for developers only, because `module.json` keeps its `%ModuleVersion%` placeholder until a release is packaged. To work on the module, clone the repository.

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
