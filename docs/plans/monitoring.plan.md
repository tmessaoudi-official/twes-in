# monitoring Plan

## Decisions Log
- [2026-10-12 15:10] AGREED: the uptime monitor is Gatus (Apache-2.0) on a second small machine at another provider, its checks a YAML file in the repository (infra/monitor/) held by a gate, its status page linked from the sign-in footer (developer)
- [2026-10-12 15:10] AGREED: errors go to GlitchTip (MIT), self-hosted on the monitor machine: the API reports through sentry-symfony (MIT) scrubbed of personal data, the web posts its errors to the API's own endpoint which forwards them, with no browser SDK and no third-party script (developer)
- [2026-10-12 15:10] AGREED: alerts reach the developer by email through a relay account other than the application's, and by a Telegram bot; alert texts carry no customer data (developer)
- [2026-10-12 15:10] AGREED: the server's inner state reaches the monitor through a token-protected /api/health/deep read every minute (queue, disk, WAL lag, certificate) and a heartbeat each successful backup step sends, a missing heartbeat raising an alert (developer)
- [2026-10-12 15:16] AGREED: the status page is Gatus's own dashboard, public, every check hiding its URL, host, port, conditions and errors under a generic name; pushes keep their own tokens (developer)
- [2026-10-12 15:16] AGREED: the monitor machine's provider is named like the backup one: a legal.monitor.provider platform setting (provider and country), a line in the three DPA drafts and the privacy page (developer)
- [2026-10-12 15:16] AGREED: plan approved: deep health, backup heartbeats, API errors through sentry-symfony, web errors through the API, the monitor machine's compose (Gatus, GlitchTip all-in-one), make monitor-check, the status link and legal line, then the deploy overlay requiring the monitor (developer)
- [2026-10-12 15:31] AGREED: heartbeats leave the backup image through Debian's curl, installed pinned from the same signed sources and aggregated like pgBackRest, restic and supercronic, named in the notices sentence (developer)
- [2026-10-12 15:31] AGREED: the backup image fetches only its own architecture's supercronic asset, still checked against its pinned sha256: the deploy check's first CI failure was that asset's download (developer)
- [2026-10-12 15:45] ASSUMED (review): The error scrubber masks addresses, anything quoted ("…", «…», '…') and runs of six digits or more in messages and exception values, keeps the URL without query, drops user, extra, breadcrumbs and frame variables — because a message may name a customer's value and these shapes cover the ones the code writes. Alternatives: drop messages entirely (reports become unreadable); mask nothing beyond send_default_pii (values leak).
- [2026-10-12 16:05] ASSUMED (review): POST /api/client-errors keeps the CSRF header (the page already sends it, signed in or not), budgets 20 reports per client per 15 minutes, refuses a body over 16 KB with 413, cuts the message to 500 and the stack to 4,000 characters, keeps the page path without query or fragment, and masks the line with the scrubber before logging on a new 'client' channel — because the instance's own log must hold no more than the tracker. The web reports at most 5 errors per page load and leaves an HttpErrorResponse out (the API logged it). Alternatives: no CSRF (any site could flood it); report API answers too (each 5xx twice).

## Formal Plan

Stage 1 step 6 (P3), row 270. Every slice test-first, its own commit, gates in order, CI green before the next push.

1. **Deep health** (`Shared/Infrastructure/Health`): `GET /api/health/deep`, `Authorization: Bearer <MONITOR_TOKEN>` compared
   in constant time, 404 otherwise (sweep test lists it). Checks: failed messages = 0; oldest waiting message < 10 min (a
   dead worker); free space on the files volume > 20 %; WAL archiving, expected only where the backup layer says so
   (`BACKUP_EXPECTED=1`, set by compose.backup.yaml): `archive_mode` on, something archived, `last_failed_time` not newer
   than `last_archived_time`. 200 or 503 with the failing check's name, never data. Sabotage: `archive_command=/bin/false`
   answers 503 within minutes.
2. **Heartbeats**: `twes-backup` pushes `success=true` after each step that worked and `success=false` with the step's
   name when one fails, to Gatus's external endpoints (base backup 26 h, copy 26 h, verify 8 days). No `MONITOR_URL`
   (development, the drill) means no heartbeat by design; the deploy overlay requires it. A heartbeat that cannot be sent
   never fails the backup: the monitor being down is what the missing-heartbeat alert reports.
3. **Errors from the API**: `sentry/sentry-symfony` (MIT, licence gate, notices) in prod only, behind an Infrastructure
   adapter; `send_default_pii: false`, `max_request_body_size: never`, cookies, headers and query values dropped,
   addresses masked in messages; 1 s connect / 2 s send. Tests: an event from a failed sign-in carries no password or
   address; worker mode keeps no scope between requests; a blackholed DSN host (non-routable address) adds at most the
   timeout, measured.
4. **Errors from the web**: `POST /api/client-errors` (public, CSRF as every request, rate-limited per address, payload
   capped, sweep test), forwarded through the same port tagged `source:web`; a web `ErrorHandler` in `shared/`, at most
   5 reports a page, the path without its query, the build; the sender loaded lazily, the first page measured.
5. **The monitor machine** (`infra/monitor/`): `compose.yaml` with Gatus 5.37, GlitchTip 6.1.8 all-in-one and its
   PostgreSQL (no Valkey), a Caddy edge; `gatus.yaml` (the app root, `/api/health`, `/api/health/deep` with the token,
   certificate expiry > 14 days, the three heartbeats, alerts by email and Telegram, failure threshold 2);
   `scripts/monitor/new-monitor.sh` writes its values; `infra/monitor/bootstrap.py` makes the first organisation,
   project and DSN; `ENABLE_USER_REGISTRATION=false`. Gate `monitor-config.sh`: every heartbeat present, every alert on
   both channels, the token never inline, registration off, details hidden where the ruling below says.
6. **`make monitor-check`** and its workflow: the deploy check's stack plus the monitor bundle; Gatus reads deep health
   green, a heartbeat arrives, a provoked API exception and a web error reach GlitchTip's API with no password and no
   address in them.
7. **Status link and legal**: `support.status_url` platform setting, the sign-in footer link when set; the legal line per
   the ruling below; fr.json measured (about 107 B of the 242 left).
8. **Deploy side, docs, sabotages**: `MONITOR_TOKEN` (generated), `MONITOR_URL`, the heartbeat token and `SENTRY_DSN`
   (facts from the monitor) required in compose.deploy.yaml, new-instance.sh, deploy-overlay.sh and the deploy check;
   START.md § 15, UPDATE.md pins, SPEC § 7 and row 270, CLAUDE.md. A deployment needs the monitor machine from then on.

Not covered, said so: nothing watches the monitor machine; web stacks stay minified (no source maps uploaded).

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Deep health behind a token | M | done | 5b88cf9d | api/src/Shared/Infrastructure/Health/** api/tests/** compose.backup.yaml |
| 2 | Backup heartbeats | S | done | 5b88cf9d | infra/postgres/backup/** compose.backup.yaml |
| 3 | API errors through sentry-symfony, scrubbed | M | doing | - | api/** |
| 4 | Web errors through the API | M | todo | - | api/src/** web/src/app/shared/** |
| 5 | Monitor machine compose, gatus.yaml, its gate | M | todo | - | infra/monitor/** scripts/monitor/** scripts/gates/** |
| 6 | make monitor-check and its workflow | M | todo | - | scripts/monitor/** .github/workflows/** Makefile |
| 7 | Status link and legal monitor provider | S | todo | - | api/src/** api/resources/legal/** web/** |
| 8 | Deploy overlay requires the monitor, docs, sabotages | M | todo | - | compose.deploy.yaml scripts/** docs/** CLAUDE.md |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
