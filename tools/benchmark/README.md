# Load benchmark

Measures whether Call Feedback's results pages stay fast and within PHP's memory on a busy PBX. No phone calls are placed: the survey sessions are synthetic.

## Method

`run.php` builds a module database from the module's own model annotations and one active survey (CSAT, then "was your issue resolved?"), fills it with a year of sessions, and times the page's queries with the module's own code.

- **Sessions:** the given number a weekday (a quarter of that at weekends, ±15% day to day), between 09:00 and 18:00; 85% answer every question, 6% only the first, 9% press nothing; 4% are left out as too short; 80% inbound; ten agents, three queues and three lines. CSAT answers skew positive, as real ones do (6% 1, 6% 2, 14% 3, 34% 4, 40% 5); low scores open follow-ups. Rule skips are counted every day.
- **Queries:** p50 and p95 of the Overview (which also reads the previous period), Survey health and every breakdown over the last 30, 90 and 365 days, the first page of follow-ups and the survey list. The table shows the slowest breakdown.

```bash
php tools/benchmark/run.php --per-day=200 --days=365 [--runs=7] [--json=result.json]
```

The results below were measured in Docker's official `php:8.4-cli` image (PHP 8.4.27, the version MikoPBX 2026.6.34 runs), limited to 2 CPUs, with `memory_limit=128M`: the admin cabinet's PHP has no memory setting of its own, so it runs with PHP's default of 128 MB. Run `composer install` first; the runner uses the test helpers.

## Results (November 2026)

Target: **200 surveyed calls a day for a year**, a busy small office. The 1,000-a-day run shows headroom and is not a target.

| | 200 a day | 1,000 a day |
| --- | --- | --- |
| Sessions / answers in 365 days | 57,356 / 101,100 | 287,837 / 507,254 |
| Module database size | 13 MB | 65 MB |
| Peak PHP memory | **10 MB** | 24 MB |
| Overview, last 30 days (p95) | 24 ms | 132 ms |
| Overview, last 90 days (p95) | 71 ms | 406 ms |
| Overview, last 365 days (p95) | 159 ms | 931 ms |
| Survey health, last 365 days (p95) | 129 ms | 714 ms |
| Slowest breakdown, last 365 days (p95) | 141 ms | 778 ms |
| First page of follow-ups (p95) | 6 ms | 26 ms |
| Survey list (p95) | 5 ms | 27 ms |

**Gate:** passed at 200 a day: every query is well under a second, and memory stays far below 128 MB.

An earlier version loaded every session of a period into memory before counting, and the Overview loaded two periods. At 200 a day its year view peaked at 110 MB, just under the limit, and at 1,000 a day at 532 MB, which the cabinet would refuse. The results now read sessions one at a time and keep only counts and answer values.
