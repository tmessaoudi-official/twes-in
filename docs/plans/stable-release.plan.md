# stable-release Plan

The next tag is the real stable release: known bugs fixed, stress-tested, uncorruptable, reachable from outside the shop,
movable to another PC from a backup, a guarded wipe, the configuration widened and the design system renewed.
Read-only maps: `var/claude/hardening-map/`.

## Decisions Log
- [2026-10-31 19:29] AGREED: one release carries everything (bugs, security, exposure, migration, wipe, stress, configuration, design), with a longer freeze, a release candidate rehearsed on the shop's real data and a tested rollback before the tag (developer's answer, against the recommended split).
- [2026-10-31 19:29] AGREED: who reaches the app from outside the shop: the owner and staff (home, phone) and the developer for support; not the shop's customers.
- [2026-10-31 19:29] AGREED: the wipe offers three scopes (whole installation, one company, test/demo data only), each backing up first, typed confirmation, listing fiscal data found and refusing it within legal retention unless explicitly acknowledged, audited.
- [2026-10-31 19:29] AGREED: the public host name is not fixed: a parameter asked at install and changeable later (developer's answer; port 443 standard, port 80 to be confirmed against the router's own interface).
- [2026-10-31 19:34] AGREED: outside access is a private VPN (Tailscale) first; the public DDNS path is built, hardened and tested but stays off until needed. Every part of the setup is documented in a clear hierarchy the developer can follow to configure it (developer's answer; whether Tailscale works through the shop's Topnet router, and any ISP, to be confirmed).
- [2026-10-31 19:34] AGREED: the public HTTP and HTTPS ports are installation parameters with defaults (HTTPS 443, HTTP off); the shop starts with 443 only.
- [2026-10-31 19:34] AGREED: the public host name is asked at install (optional) and changed later by a `twes` command that checks the name resolves to the shop, says what breaks (passkeys, sent links), backs up, asks a typed confirmation and restarts the edge; the LAN address keeps working.
- [2026-10-31 19:35] AGREED: Tailscale with one shop account on the free plan (the shop PC and staff devices) plus the developer as a support user; the app at https://<pc>.<tailnet>.ts.net with its real certificate; the docs give install, the `tailscale netcheck` feasibility test, adding/removing a device and a lost phone; the developer checks the plan's business-use terms.
- [2026-10-31 20:33] AGREED (written at 2026-10-31 20:40; the first write was refused by a hook): the way to the start: advisor() checks the plan; the one-way and legal decisions asked here in rounds; the rest and the full plan on a marking page; then a design-system page built last so it takes every decision into account, approved by the developer; then the effort recomputed; then the developer is asked to switch on autonomous mode, keep-going, unattended and confident picks, and implementation waits for that answer.
- [2026-10-31 20:40] AGREED: no patch tag before the stable release: the six built fixes (FC#264-266, 268, 269, 277) and FC#276 reach the shop with the stable tag (developer's answer, against the recommended week-1 patch).
- [2026-10-31 20:40] AGREED: if the stable tag is not out by 2026-12-15, one interim patch on a maintenance branch carries only the TN 2027 finance-law values and their presets, rehearsed like a release.
- [2026-10-31 20:40] AGREED: the proposed deferrals (per-document foreign currency, SPEC#274 Stage 3, price lists by kind/area/channel, SPEC#332, screens-maturity build beyond its rulings, SPEC#171, full RGAA audit, rows blocked on outside facts) were never asked for: they stay out of this release, not added to it. The developer asked to review the scope: the 16-20 week estimate is re-laid out before any calendar decision.
- [2026-10-31 20:55] AGREED: the scope split: this release ships, most urgent first, P0 whole; the uncorruptable core (concurrency of numbering and stock, double submits, kill mid-write, data checks, restore fidelity, fiscal XML schema validation, imports off the request, DB triggers on issued invoices, fuzzing of import files); safe outside access (Tailscale, LAN-only firewall, /platform inside only, client address proved, lockout-proof, backups the PC cannot destroy, PC as a server, monitor, twes doctor --network, failed-login burst alerts, staff device management); P3 whole; P4 whole; known bugs on the screens used (stale, silent, missing actions, refusal reasons, unsaved work, half-built rows finished or hidden); core configuration (dated law changes, default semantics, limits and assumptions as settings, currencies as data); core design (tokens, empty states, phone cards, wide screens, text size, calme/vif themes, dark mode, home, small flaws); docs (configuration hierarchy, Tailscale, runbook); P9 whole. The next release: public-route fuzzing, 24 h soak, long-open tab, property-based tests, a second alert channel, threat-model items outside access does not need, brand kit, guided tours, staff guide, paper invoices (accountant). The public path, presets and the calendar are re-asked.
- [2026-10-31 21:01] AGREED: the public path is built, hardened and tested in this release and switched off at the shop; its host and ports are installation settings in every case (confirms 19:34 against the recommended settings-only).
- [2026-10-31 21:01] AGREED: business profiles start with two, one per first customer (a retail shop with stock and a made-to-order machining workshop), each data (modules, defaults, units, catalogue structure) chosen at company creation; in-app editing and more profiles in the next release. Their names are re-asked.
- [2026-10-31 21:07] AGREED: speed without compromise: no cloud sessions; every remaining decision made before the start (marking page, then the design page), the shop check as early as the developer can, a review lens at the end of each phase; this machine keeps 2 builders + 1 hunter. The developer wants it as fast as possible with nothing sacrificed.
- [2026-10-31 21:07] AGREED: the two business profiles are named by class with trade examples: « Commerce avec stock » (ex. quincaillerie, droguerie) and « Atelier sur commande » (ex. tournage, usinage, menuiserie); en and ar translated.
- [2026-10-31 21:07] AGREED: the shop check (Tailscale netcheck, router and PC facts) is done by the developer as soon as he can, from the step-by-step given in the conversation; the three access steps it gates wait for his result, everything else proceeds.
- [2026-10-31 21:14] AGREED: the marking page (artifact VpUL6HwdU85YVRkicKjJ5i, read back: 28 of 28 marked, every one the recommended option a, no notes): Q1 passkey address a platform setting off by default, switched to the tailnet name at the shop, refused while anyone's only factor is a passkey; Q2 erasing a company with issued invoices refused until its archive is downloaded and verified, then acknowledged with the 10-year duty; Q3 --with-issued-documents + name + a typed sentence of the counts; Q5 second factor required for owners/admins with Tailscale on, offered to others, required for all if public; Q6 links carry the tailnet name when on, else the LAN; Q7 ask the accountant, meanwhile month grain and a refusal of a date before the previous day with an audited operator override; Q8 5 writers, p95 < 500 ms, zero 5xx on 2 cores/4 GB; Q9 own load script + OWASP ZAP in a container; Q10 Tailscale client on Windows, its cert handed to Caddy; Q11 a yearly sealed archive off-site kept 10 years + USB copy; Q12 write-only key on the PC, prune key with the developer, Object Lock if free; Q13 BitLocker with escrowed key, no AV exclusion; Q14 each money/stock mistake corrected by a new record, country/currency editable only before anything is issued; Q15 a console command after a labelled backup for old audit values; Q16 no cosign this release; Q17 gpg --symmetric; Q20 taxBasis a preset default overridable where the preset allows; Q23 185 write permission, 186 previous value as a correction record, 187 name kept on issued documents and anonymised elsewhere; Q24 security releases at the next tick with a mail; Q25 Arabic labelled aperçu until read; Q26 the router's DDNS client; Q27 Tailscale SSH for the developer's device only; Q28 twes rehearse on the shop PC; Q30 no cut, an alarm on 2026-12-10; Q31 the master key only by twes escrow with the developer; Q32 no tag 2026-12-20 to 2027-01-10 except a hotfix; Q33 a stand-in tailscale binary here; QA a page of the ASSUMED picks this release's code rests on, marked before the tag.
- [2026-10-31 21:14] AGREED: off-site backups may go to the shop's Google Drive: restic writes through rclone (MIT), encrypted before it leaves the PC, as one destination beside S3-compatible ones, each a setting; the Q12 guarantee is kept by a nightly copy the developer's machine pulls from that Drive and never deletes from. A Drive folder synced on the PC is refused (ransomware propagates through sync, half-synced repositories, WSL reads the virtual drive poorly). About 1-1.5 days at 24/7.
- [2026-10-31 22:03] AGREED: the design is judged on mockups before any code: one page with switches (calme/vif, light/dark, text size 100/112/125 %, fr/ar, desktop/phone) preset to the recommendations, showing every screen family (about 15); D1-D5 are decided on that page. No surprises: the page carries a coverage table mapping every route the code declares to the mockup that shows its pattern, and a screen fitting no family gets its own mockup.
- [2026-10-31 23:04] AGREED: design decisions from the mockup page (artifact RzEGCYwSuaNmgzBrG3TJTS): D1 new accounts start on « vif » (developer's answer, against the recommended calme); D2 the text size belongs to the device, calme/vif to the person; D3 in Arabic a chart's time axis is mirrored, newest month on the left, to confirm with an Arabic reader. The system is approved « I guess » with the remark that the difference from today is too small (tones and backgrounds only): how far to go beyond it is re-asked; D4 (text-heading) and D5 (no home reorder by profile) follow that answer.
- [2026-10-31 23:12] AGREED: design scope: this release ships the renewed system plus a lively layer (a colour per area on menu icons, page headers and home tiles; small inline-SVG illustrations for empty lists, first steps and « Bientôt » pages; a home that tells the day (sales with a mini curve, invoices to chase, low stock with its action, first steps celebrated); cues in lists (thumbnails, coloured initials, paid bar, stock gauge); small motion off under reduced motion; swipe cards on the phone), mocked up again for the developer's validation before the start (+4-6 days at 24/7). D4 text-heading kept; D5 no home reorder by profile this release. A full new identity is explored now as 2-3 complete mockup directions on the same screens, compared with the shop's owner and staff, and the chosen one is built in the next release.
- [2026-11-01 09:48] ASSUMED (review): lively layer L2: the panel fade (M3) is dropped; only a row fade after a skeleton, a sheen stopping after 4.8 s, nothing under reduced motion, plus an in-app « Animations : Système | Réduites » setting — because the taste and a11y attacks found it added motion without meaning. Alternatives: keep M3.
- [2026-11-01 09:48] ASSUMED (review): lively layer L5: customer initials neutral beside a status pill (documents, watch) and coloured on the customers and vendors lists — because a coloured initial beside a status tone read as a second status. Alternatives: coloured everywhere, neutral everywhere.
- [2026-11-01 09:48] ASSUMED (review): lively layer T2: « Premiers pas » sits right under the home header while steps remain and moves last once all are done — because it is the first job of a new company and noise after. Alternatives: fixed place.
- [2026-11-01 09:48] ASSUMED (review): lively layer T11: rows fade in only after a skeleton, never on a list already drawn — because a fade on every reload reads as flicker. Alternatives: always fade, never fade.
- [2026-11-01 10:01] AGREED: lively layer validated as shown on the mockup page (artifact 2g84TwW2w4jRvNPPhZ3R4c): L1 the seven area colours kept and the close pairs separated by lightness (tokens recomputed, contrast re-checked; each area also named and iconed); L3 all of it, 9 days at 24/7 (Atelier panels, paid bar and phone swipe included); L6 « À relancer » stays its own tile and the four ruled tiles stay in the accent; L7 « Ventes du jour » kept.
- [2026-11-01 10:14] ASSUMED (review): L4 swipe cards on the phone cover invoices and delivery notes only, not quotes — reversible and in scope, no ruling decides; advisor() agreed (2026-11-01 goal-start call). Alternatives: quotes too.
- [2026-11-01 10:14] AGREED: the developer switched on autonomous mode, keep-going, unattended (allow) and confident picks before the final effort was shown (his instruction: « start implementing and keep going »); the 20:33 sequence is shortened accordingly: implementation starts with P0, and the final plan, its effort and the identity page are presented when the background workflow returns.
- [2026-11-01 12:00] ASSUMED (review): L1 dark status-tone floor lowered from dE 8 to 7.75 (shipped values sit >= 7.85 from every coloured tone) — because under 8 no dark layout separates the close area pairs (best 2.38). Alternatives: keep 8 and leave dark as revision 1.
- [2026-11-01 12:00] ASSUMED (review): L1 in light, achats, argent and rapports darken while ventes, clients and stock keep their colour (L >= 0.48) — because those are the areas seen most; other pairs' worst moves 6.1 -> 4.9, close pairs now >= dE 10 under every colour-blindness simulation, 0 contrast failures, 1,002 renders clean. Alternatives: free choice (ventes near black).
- [2026-11-01 13:01] AGREED: the 2026-12-15 interim patch carries the 2027 finance-law values AND the seven built fixes (FC#264-266, 268, 269, 277, 276), rehearsed like a release; supersedes 2026-10-31 20:40's « no patch before the stable tag » (developer's answer once the final plan put the tag in 2027).
- [2026-11-01 13:01] AGREED: W6 per-company signing (S2.17) goes to the next release: not needed for outside access, and a one-way door on issued fiscal documents.
- [2026-11-01 13:01] AGREED: the accent presets near a status hue (rouge at dE 1.7 from danger; rose, orange, vert close) move off the status hues in this release; a company keeps its preset in its new shade, and danger buttons get their own outline so colour is never the only cue.
- [2026-11-01 13:13] AGREED: calendar: everything agreed stays; aim for a tag around 2026-12-02 (the final plan's model rerun at the measured R26-R32 pace, S 1.5 h, M 4.5 h, L 15 h of agent work; the plan-final model had read lane-days as 24-hour days, 8 times larger); the real pace is measured at the end of P0 and at every phase end and any slip is told at once; the 2026-12-15 interim (2027 values + the seven fixes) is the safety net; the 7-day release-candidate soak is kept.
- [2026-11-01 13:28] ASSUMED (review): S0.17: app:platform:converge left as it is: it creates no operator or company, adds only what a company lacks, and sets the built-in roles to this release's sets, which are the release's own and never a company's choice (a custom role is untouched) — the row's « converge only ADDS » already holds for what a company owns. Alternatives: stop resetting built-in roles at start.
- [2026-11-01 13:28] ASSUMED (review): S0.8: the rehearsal and onprem-check proofs are keyed on the tree alone, with the commit and date written inside; not on the three image digests, because the rehearsal and onprem-check build their own images locally, whose digests never equal the registry's. Alternatives: rehearse from the published images.

## Formal Plan
The full plan is `var/claude/hardening-map/plan-final.md` (revision 2, 136 steps, critic answers in its § 13); its rows are
the Status block below, one per step, P0 to P9. Order: P0 land the built work and release safety; P1 the uncorruptable
core; P2 safe outside access (Tailscale on, public path built and off); P3 move an installation (export, import, Google
Drive); P4 the wipe, three scopes; P5 known bugs on the screens used; P6 core configuration and the two profiles; P7 the
design system, the lively layer and the identity comparison; P8 documentation in one hierarchy; P9 freeze, panel, release
candidate soaked 7 days on the shop's data, rehearsals, tag. Three rows wait for the shop check (S2.2, S2.2b, S2.4b).
Calendar (ruled 2026-11-01): aim for a tag around 2026-12-02 at the measured pace, measured again at every phase end;
the 2026-12-15 interim carries the 2027 values and the seven built fixes if no tag by then.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| S0.1 | Snapshot everything unshipped into refs/recovery (stable-start-20261101 59d21138; nb2-20261030, r32-next, nb1 kept) | S | done | - | - |
| S0.2 | Copy the 2026-10-25..11-01 rulings into SPEC § 7 (55 entries, in date order), stamped; supersede 2026-10-27 01:03, rescope FC#177/178; make gate-stamps OK | S | done | 2f140d410 | docs/SPEC.md |
| S0.3 | Land FC#264-266, 268, 269, 277 by cherry-pick (6 unique patches, all %G?=G), the RecordVersions exemption and the plan diff; ar.json 61,405 B of 61,440; gate green on tree 16737b58 | M | done | c564b7742 | api/** web/** docs/plans/** |
| S0.4 | Finish and land FC#276 (platform_setting live kind) from refs/recovery/nb2-20261030; applied clean, make check green, contracts 39 OK, sabotage of the stage caught | M | done | 0ff600fcf | api/src/** web/src/app/** web/e2e/** |
| S0.5 | Status-block truth across every plan and SPEC § 8 | M | done | - | docs/** TODO.md |
| S0.6 | Remove landed worktrees and branches, keeping refs/recovery | S | done | - | - |
| S0.7 | Settings texts part out of the main fr/en/ar files (ar has 35 B left) | M | done | 2ce561bfd | web/public/i18n/** web/src/app/settings/** |
| S0.8 | Release-evidence gates in tag-release.sh and additive-migrations.sh | M | done | 283efa6f1 | scripts/release/** scripts/gates/** |
| S0.9 | Flake and budget classes as gates (limiters, settled reads, test clock, docs run lines) | M | done | 2ce561bfd | api/tests/** scripts/gates/** |
| S0.10 | ASSUMED marking page: 304 picks published, artifact UqoJRxiEXufS3BeJ53GUdP db marks; read back before the tag | S | done | - | - |
| S0.11 | Values upgrade: new values filled from infra/onprem/values.defaults before compose config | M | done | 283efa6f1 | scripts/deploy/** infra/onprem/** |
| S0.12 | snapshot-gate refuses a non-main tree while main-first exists (landed alone); test 41 passed, check removed: 2 fail | S | done | 864136fa2 | scripts/gate/** |
| S0.13 | StatefulServicesTest: every stateful service resets or is listed | M | done | 2ce561bfd | api/tests/** |
| S0.14 | Budget reserve measured and enforced | S | todo | - | web/angular.json scripts/gates/** |
| S0.15 | Baseline release proofs: update rehearsal and onprem-check legs with markers | M | todo | - | scripts/onprem/** scripts/release/** Makefile |
| S0.16 | Maintenance lane for patch tags | S | done | 283efa6f1 | scripts/release/tag-release.sh, scripts/onprem/twes, scripts/tests/twes.test.sh, scripts/release/tests/* |
| S0.17 | Start steps never abort the start | M | done | 283efa6f1 | infra/api/docker-entrypoint.sh, api/src/Tenancy/Infrastructure/Console/ConvergePlatformCommand.php, api/src/Shared/Infrastructure/Http/HealthController.php, api/tests/Functional/StartStepsTest.php |
| S0.E | P0 phase-end lens and legs | S | todo | - | - |
| S1.1 | Concurrency harness and make stress | M | todo | - | api/tests/Concurrency/Support/*, Makefile, api/phpunit.dist.xml |
| S1.2 | Stock check under the stock lock | M | todo | - | api/src/Module/Invoices/**, api/src/Module/DeliveryNotes/**, api/src/Module/Inventory/Application/**, api/tests/Concurrency/Stock* |
| S1.3 | Parallel issue on every numbered series, 409 not 500 | M | todo | - | api/tests/Concurrency/Numbering*, api/src/Shared/Infrastructure/ApiPlatform/**, api/src/Module/Customers/** |
| S1.4 | Double submits across tabs and keys | S | todo | - | api/tests/Concurrency/Submission*, api/src/Module/Invoices/** |
| S1.5 | Money and stock lock sites contested | M | todo | - | api/tests/Concurrency/** |
| S1.6 | Data checks for every unstated invariant | L | todo | - | api/src/Module/*/Infrastructure/DataHealth/*, api/src/Files/Infrastructure/DataHealth/*, api/tests/Functional/DataCheckTest.php, api/tests/Architecture/DataCheckCoverageTest.php |
| S1.7 | DB guard on issued fiscal rows | L | todo | - | api/migrations/Version2026110*.php, api/src/Tenancy/Application/Closing/RemoveClosedCompanies.php, api/src/Demo/**, api/src/DataFixtures/Scale/ScaleGenerator.php, api/tests/Integration/FiscalGuard*Test.php |
| S1.8 | Kill mid-write, orphan files, disk full, dependencies down | L | todo | - | scripts/stress/chaos.sh, compose.stress.yaml, compose*.yaml, api/src/Files/** |
| S1.9 | Clock: month grain, refusal with audited override, drift alert | M | todo | - | api/src/Tenancy/Domain/Model/NumberingSeries.php, api/src/**, scripts/onprem/twes, infra/postgres/backup/twes-backup, scripts/tests/twes.test.sh |
| S1.10 | Imports, exports and PDFs off the request | L | todo | - | api/src/ImportExport/**, api/src/Module/*/Infrastructure/Import/*, web/src/app/shared/import/**, web/e2e/import*.spec.ts, api/migrations/*, additive) |
| S1.11 | Load to the SLO, no soak | M | todo | - | scripts/stress/load.mjs, compose.stress.yaml, Makefile |
| S1.12 | Fuzz the import files | M | todo | - | api/tests/Fuzz/** |
| S1.13 | Restore fidelity and monthly shop verify | M | todo | - | scripts/backup/restore-drill.sh, infra/postgres/backup/twes-backup, infra/postgres/backup/crontab*, scripts/tests/backup-steps.test.sh |
| S1.14 | Fiscal XML schema validation in CI | M | todo | - | api/tests/**/CiiInvoiceSchemaTest.php, api/tests/**/TejDeclarationSchemaTest.php, scripts/fiscal/fetch-xsd.sh, .github/workflows/ci.yml |
| S1.15 | Remaining data-integrity defects | L | todo | - | api/src/Module/**, api/tests/** |
| S1.E | P1 phase-end lens and legs | S | todo | - | - |
| S2.1 | twes doctor --network | S | todo | - | scripts/onprem/twes, scripts/onprem/install.ps1, scripts/tests/twes.test.sh |
| S2.2 | Tailscale spike, ruling after the shop check (shop check) | M | todo | - | docs/SPEC.md |
| S2.2b | Q10: Tailscale client on Windows, cert handed to Caddy (shop check) | M | todo | - | scripts/onprem/install.ps1, scripts/onprem/twes, infra/powershell/**, scripts/tests/twes.test.sh |
| S2.3 | Host and origin values TAILNET_HOST, PUBLIC_HOST, ports | L | todo | - | compose.onprem.yaml, infra/onprem/Caddyfile, api/config/packages/framework.yaml, api/config/services.yaml, api/src/Legal/Application/SecurityTxt.php, api/src/Module/Scanning/**, scripts/onprem/twes, scripts/onprem/install.ps1, scripts/deploy/*.sh, scripts/gates/onprem-overlay.sh |
| S2.4 | Edge: LAN, public built and off, headers, limits, health trimmed | L | todo | - | infra/onprem/Caddyfile, infra/web/nginx.conf, api/src/Shared/Infrastructure/Http/HealthController.php, api/tests/Functional/HealthTest.php, scripts/onprem/onprem-check.sh |
| S2.4b | Tailnet site with its real certificate (shop check) | M | todo | - | infra/onprem/Caddyfile, scripts/onprem/onprem-check.sh |
| S2.5 | Platform and operator surfaces LAN and tailnet only | M | todo | - | infra/onprem/Caddyfile, infra/web/nginx.conf, api/config/packages/security.yaml, api/tests/Functional/PlatformReachTest.php |
| S2.6 | Windows firewall to the LAN | M | todo | - | scripts/onprem/install.ps1, scripts/onprem/twes, scripts/onprem/wsl-engine.sh, infra/powershell/** |
| S2.7 | Client address proved, trusted proxies narrowed | M | todo | - | scripts/onprem/onprem-check.sh, api/config/packages/framework.yaml, api/src/**, api/tests/Functional/AuthTest.php |
| S2.8 | Lockout keyed per account and address, MFA defaults | M | todo | - | api/src/Identity/**, api/src/Tenancy/Domain/Model/Company.php, api/tests/Functional/AuthTest.php |
| S2.9 | Demo refused at the API on a shop install | S | todo | - | api/src/Demo/**, compose.onprem.yaml, scripts/onprem/twes, api/tests/Functional/Demo*Test.php |
| S2.10 | Passkey RP id switch, off by default | M | todo | - | api/config/services.yaml, api/src/Identity/Infrastructure/Passkey/**, web/src/app/account/**, api/tests/Functional/Passkey*Test.php |
| S2.11 | twes host set, ports set, public on and off | M | todo | - | scripts/onprem/twes, scripts/tests/twes.test.sh, docs/START.md |
| S2.12 | Detection alerts and the security release channel | M | todo | - | scripts/onprem/twes, api/src/Installation/**, scripts/tests/twes.test.sh, scripts/release/tag-release.sh, scripts/release/tests/* |
| S2.13 | Off-site backups a compromised PC cannot destroy | M | todo | - | infra/postgres/backup/twes-backup, compose.backup-offsite.yaml, scripts/onprem/twes, scripts/tests/backup-steps.test.sh |
| S2.14 | Shop PC as a server | M | todo | - | scripts/onprem/install.ps1, infra/powershell/**, scripts/onprem/twes, compose.onprem.yaml |
| S2.16 | Security leftovers with a row | L | todo | - | api/src/Audit/**, api/src/Identity/**, api/src/Records/**, api/src/Module/**, api/src/Shared/Infrastructure/ErrorReporting/ClientErrorController.php, api/tests/** |
| S2.17 | W6 per-company signing key with escrowed master key: next release (ruled 2026-11-01) | L | deferred | - | api/src/Module/Invoices/**, api/src/Fiscal/**, api/migrations/* |
| S2.18 | IPv6 exposure closed | M | todo | - | compose.onprem.yaml, scripts/onprem/install.ps1, scripts/onprem/twes, scripts/gates/onprem-overlay.sh |
| S2.19 | Outside-in liveness heartbeat | M | todo | - | scripts/onprem/twes, infra/monitor/gatus.yaml, scripts/gates/monitor-config.sh, scripts/monitor/monitor-check.sh |
| S2.20 | Staff devices and sessions | M | todo | - | api/src/Identity/**, web/src/app/company/**, web/src/app/account/**, web/e2e/sessions.spec.ts, docs/RUNBOOK.md |
| S2.21 | Tailnet ACL and developer SSH | S | todo | - | infra/onprem/tailnet-policy.hujson, docs/START.md, scripts/onprem/twes |
| S2.22 | Host drift checks and install.ps1 -Upgrade | M | todo | - | scripts/onprem/install.ps1, infra/powershell/**, scripts/onprem/twes, scripts/tests/twes.test.sh |
| S2.23 | Monitor machine set up with the developer (developer) | S | todo | - | docs/START.md, infra/monitor/* |
| S2.E | P2 phase-end lens and legs | S | todo | - | - |
| S3.1 | twes export with manifest and sealed values | L | todo | - | scripts/onprem/twes, scripts/onprem/lib/export.sh, scripts/tests/twes.test.sh |
| S3.2 | twes import with version refusal and verification | L | todo | - | scripts/onprem/twes, scripts/onprem/lib/import.sh, infra/api/migrate.sh, scripts/tests/twes.test.sh |
| S3.3 | Move check and weekly onprem-check in CI | M | todo | - | scripts/onprem/move-check.sh, Makefile, .github/workflows/move-check.yml, .github/workflows/onprem-check.yml |
| S3.4 | Verifiable escrow | M | todo | - | scripts/onprem/twes, scripts/tests/twes.test.sh |
| S3.5 | Lost machine from backup automated | M | todo | - | scripts/onprem/twes, infra/postgres/backup/twes-backup, scripts/tests/twes.test.sh, docs/RUNBOOK.md |
| S3.6 | Recovery points, encrypted dump, yearly archive | M | todo | - | infra/postgres/backup/twes-backup, infra/postgres/backup/crontab*, compose.backup*.yaml, scripts/tests/backup-steps.test.sh |
| S3.7 | Ops leftovers FC#18, 75, 77, 78, 99, 126, 128 | L | todo | - | scripts/onprem/twes, compose*.yaml, scripts/gates/secrets-overlay.sh, .github/workflows/*, scripts/tests/twes.test.sh |
| S3.8 | Move docs and re-pointing checklist | S | todo | - | docs/RUNBOOK.md, docs/START.md |
| S3.9 | twes rehearse on the shop backup | M | todo | - | scripts/onprem/twes, scripts/onprem/lib/rehearse.sh, scripts/release/tag-release.sh, scripts/onprem/onprem-check.sh |
| S3.10 | Google Drive off-site via rclone and the developer pull copy | M | todo | - | infra/postgres/Dockerfile, infra/postgres/backup/twes-backup, compose.backup-offsite.yaml, scripts/backup/pull-drive-copy.sh, scripts/tests/backup-steps.test.sh, THIRD-PARTY-NOTICES.md |
| S3.E | P3 phase-end lens and legs | S | todo | - | - |
| S4.1 | app:fiscal:inventory with coverage test | M | todo | - | api/src/Fiscal/**, api/tests/Integration/FiscalInventoryCoverageTest.php, api/tests/Functional/FiscalInventoryTest.php |
| S4.2 | twes reset, installation scope | L | todo | - | scripts/onprem/twes, scripts/deploy/deploy.sh, Makefile, api/src/Tenancy/Infrastructure/Console/PlatformResetCommand.php, scripts/tests/twes.test.sh, scripts/onprem/onprem-check.sh |
| S4.3 | Company scope erasure and Tout effacer | L | todo | - | api/src/Erasure/**, api/src/Module/*/Infrastructure/Erasure/*, web/src/app/data-erasure/**, web/e2e/data-erasure.spec.ts, api/tests/Functional/DataErasureTest.php |
| S4.4 | Test-data scope with mark, backup and ack | M | todo | - | api/migrations/*, api/src/Tenancy/**, web/src/app/company/**, api/tests/Functional/TestCompanyWipeTest.php |
| S4.5 | make reset guarded | S | todo | - | Makefile, docs/RUNBOOK.md, docs/START.md, scripts/gates/tests/* |
| S4.6 | Tests issue through the use case, shortcut gated | S | todo | - | api/tests/Functional/DataErasureTest.php, scripts/gates/test-issue-shortcut.sh |
| S4.7 | Final archive offered before closed-company removal | M | todo | - | api/src/Tenancy/Application/Closing/*, api/src/Inbox/**, api/tests/Functional/ClosedCompanyRemovalTest.php |
| S4.E | P4 phase-end lens and legs, RC0 recorded | S | todo | - | docs/plans/stable-release.plan.md |
| S5.1 | Stale screen class | M | todo | - | web/src/app/**, api/src/**, scripts/gates/live-reload.sh, scripts/gates/effect-kinds.sh |
| S5.2 | Silent result class | M | todo | - | api/src/**, web/src/app/** |
| S5.3 | Refusal reasons onto the refusal contract | L | todo | - | api/src/**/Processor/*, api/src/**/Domain/Exception/*, web/src/app/**, scripts/gates/domain-refusals.sh, web/public/i18n/refusals/* |
| S5.4 | Missing action class | L | todo | - | api/src/**, web/src/app/**, api/migrations/*, additive) |
| S5.5 | Money and stock mistakes put right | L | todo | - | api/src/Module/Invoices/**, api/src/Module/Expenses/**, api/src/Module/Inventory/**, api/src/ImportExport/**, web/src/app/** |
| S5.6 | Unsaved work kept across a session end | M | todo | - | web/src/app/shared/form/**, web/src/app/shared/session/**, web/e2e/session-end-unsaved.spec.ts |
| S5.7 | One-canon rows on the screens used | L | todo | - | web/src/app/**, api/src/** |
| S5.8 | Invisible marks gate on web inputs | S | todo | - | scripts/gates/typed-codes.sh, web/src/app/shared/form/** |
| S5.9 | 200 percent text and reflow e2e | M | todo | - | web/e2e/reflow.spec.ts, web/src/styles.scss |
| S5.10 | Slow unit cases one page per case | M | todo | - | web/src/app/**/*.spec.ts, scripts/gates/one-page-per-case.sh |
| S5.11 | Correction flows and contract leftovers | L | todo | - | web/src/app/**, api/src/**, web/e2e/** |
| S5.13 | Half-built rows finished or hidden | M | todo | - | docs/SPEC.md, docs/plans/*.plan.md, web/src/app/** |
| S5.E | P5 phase-end lens and legs | S | todo | - | - |
| S6.1 | Default-change semantics | S | todo | - | api/src/Settings/**, api/tests/Architecture/SettingDefaultsChangedTest.php, docs/release-notes/* |
| S6.15 | R32 equivalence with flags at default | S | todo | - | api/tests/Integration/R32EquivalenceTest.php, docs/spec/pricing-vectors.json |
| S6.2 | Dated law changes reach existing companies | L | todo | - | api/src/Fiscal/**, api/src/Tenancy/Infrastructure/Console/ConvergePlatformCommand.php, api/config/fiscal/*.yaml, api/migrations/*, web/src/app/fiscal/** |
| S6.4 | taxBasis wired, rounding strategies | M | todo | - | api/src/Module/*/Domain/**Totals.php, api/src/Shared/Domain/Service/DocumentCalculator.php, api/src/Fiscal/**, docs/spec/pricing-vectors.json |
| S6.6 | Currencies as data | M | todo | - | api/config/currencies.json, api/src/Shared/Domain/Service/AmountInWords.php, api/tests/Unit/AmountInWordsTest.php |
| S6.7 | Business caps as settings | M | todo | - | api/src/**, api/config/services.yaml, scripts/gates/list-limits.sh |
| S6.9 | Fiscal year, walk-in customer, FR legal identity | M | todo | - | api/src/Tenancy/**, api/src/Module/Reports/**, api/config/fiscal/FR.yaml, docs/fiscal/FR.md, api/templates/pdf/*, api/migrations/*, additive) |
| S6.10 | Two business profiles as data | M | todo | - | api/config/profiles/*.yaml, api/src/ModuleRegistry/**, api/src/FirstSteps/**, web/src/app/company/** |
| S6.12 | Stock-loss VAT effect said | S | todo | - | api/src/Module/Inventory/**, web/src/app/inventory/** |
| S6.E | P6 phase-end lens and legs | S | todo | - | - |
| S7.1 | Renewed design system and token gate | L | todo | - | web/src/app/shared/theme/**, web/src/app/**/*.html, web/src/tailwind.css, web/src/styles.scss, scripts/gates/design-tokens.sh |
| S7.2 | Empty states with their action | M | todo | - | web/src/app/shared/list/**, web/src/app/**/*-page.ts, web/public/i18n/list/* |
| S7.3 | Phone list cards | M | todo | - | web/src/app/shared/list/**, web/src/app/**, web/e2e/phone-lists.spec.ts |
| S7.4 | Wide screens and sticky summary | L | todo | - | web/src/app/invoices/**, web/src/app/quotes/**, web/src/app/delivery-notes/**, web/src/app/purchases/**, web/e2e/list-fit.spec.ts |
| S7.5 | Home structure and KPI tile | M | todo | - | web/src/app/*/*-nav.ts, web/src/app/shared/ui/kpi-tile.ts, web/src/app/first-steps/**, api/src/Module/*/Infrastructure/Home/* |
| S7.6 | Text size per device, calme and vif per person | M | todo | - | api/src/Settings/Application/PresentationSettings.php, web/src/app/shared/theme/**, web/src/app/shared/settings/** |
| S7.7 | Dark mode depth | M | todo | - | web/src/styles.scss, web/src/app/shared/theme/** |
| S7.9 | Small flaws and canon | L | todo | - | web/src/app/**, scripts/gates/* |
| S7.11 | Client performance trace | S | todo | - | web/e2e/perf-trace.spec.ts |
| L1 | Lively: area colours and tokens | M | todo | - | web/src/app/shared/theme/**, web/src/app/**/*.routes.ts, scripts/gates/areas-declared.sh, web/src/styles.scss |
| L2 | Lively: illustrations | M | todo | - | web/src/app/shared/illustrations/**, web/src/app/shared/list/** |
| L3 | Lively: home Aujourd'hui | L | todo | - | api/src/Module/Reports/**, web/src/app/home/**, web/public/i18n/home/* |
| L4 | Lively: list cues | M | todo | - | web/src/app/shared/list/**, api/src/Module/Products/**, web/e2e/list-fit.spec.ts |
| L5 | Lively: motion and animations setting | M | todo | - | web/src/styles.scss, web/src/app/shared/theme/**, web/src/app/app.config.ts |
| L6 | Lively: phone swipe cards | M | todo | - | web/src/app/shared/list/swipe*.ts, web/e2e/swipe.spec.ts |
| S7.12 | Identity directions compared with the owner and staff (developer) | S | todo | - | - |
| S7.E | P7 phase-end lens and legs | S | todo | - | - |
| S8.1 | docs/CONFIGURATION.md hierarchy and gate | L | todo | - | docs/CONFIGURATION.md, api/src/Settings/Infrastructure/Console/DumpSettingsCommand.php, scripts/gates/config-documented.sh, infra/onprem/values.defaults |
| S8.2 | Tailscale doc | S | todo | - | docs/START.md, docs/RUNBOOK.md |
| S8.3 | Public path doc | S | todo | - | docs/START.md, docs/RUNBOOK.md |
| S8.4 | RUNBOOK procedures tried or untried | M | todo | - | docs/RUNBOOK.md |
| S8.E | P8 phase-end lens | S | todo | - | - |
| S9.0 | QA page of ASSUMED picks marked before the tag | S | todo | - | - |
| S9.1 | Freeze | S | todo | - | docs/plans/stable-release.plan.md |
| S9.2 | Full gate and CI on the freeze SHA | M | todo | - | - |
| S9.3 | Panel round 1 and workflow hunt | M | todo | - | - |
| S9.4 | Panel fixes until a clean round | L | todo | - | - |
| S9.5 | RC, stress and load | M | todo | - | - |
| S9.6 | Update rehearsal on the shop's real data with rollback leg, twes rehearse | M | todo | - | - |
| S9.7 | Move to a fresh Windows PC and human restore drill | M | todo | - | docs/RUNBOOK.md, TODO.md |
| S9.8 | Pre-tag external scan | M | todo | - | - |
| S9.9 | Signed tag and shop update | S | todo | - | - |
| S9.10 | Post-tag acceptance at the shop | M | todo | - | - |
| S9.11 | Alarm 2026-12-10 | S | todo | - | - |
| S9.12 | Interim patch if no tag by 2026-12-15: the TN 2027 values and the seven built fixes (ruled 2026-11-01) | M | todo | - | api/config/fiscal/TN.yaml, docs/fiscal/TN.md |
| S7.13 | Accent presets off the status hues (rouge dE 1.7 from danger; rose, orange, vert close): each moved, a company keeps its preset in the new shade; danger buttons carry their own outline (ruled 2026-11-01) | M | todo | - | web/src/app/shared/theme/**, api/src/Settings/** |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
- 2026-11-01: c564b7742's batch (FC#265's spentBudgetInterceptor, from nb1) stopped the app at start on NG0200; found by the FC#276 e2e before any push, fixed with app-config.spec.ts as the class guard (lands with S0.4).
