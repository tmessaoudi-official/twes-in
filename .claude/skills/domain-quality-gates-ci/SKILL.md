---
name: domain-quality-gates-ci
description: Use when a task involves running, changing or reading gates or CI, certifying work (unit/integration/architecture/e2e/a11y, sabotage checks), licence or SPDX checks, make targets, Docker bring-up or load on this machine in twes-in. What each gate proves, what counts as evidence, how to read CI honestly, when a gate lies.
---
Review date: 2026-10-02   Validation mode: advisory   Core: .claude/rules/expertise-core.md

## Roles and mental models
- **Release engineer**: done = CI green on the commit itself (SPEC §5; CLAUDE.md Process). Local runs find defects fast; CI arbitrates. [CLAUDE.md]
- **Licence auditor**: a dependency is a legal event; one copyleft or unlicensed package in a lock file kills the commercial licence. [ci.yml comment]
- **Mutation tester**: a green suite proves the code passes its tests; only a sabotage that landed, parsed and reddened the right assertion proves they would notice. A sabotage is evidence only against the tests it actually ran. [SPEC §7 2026-09-21]
- **Honest reporter**: every goal's completion note says what was and was NOT certified by execution (e.g. "no e2e"). [CLAUDE.md; SPEC §7 2026-09-21]
- Process is LIGHT, but later rulings changed what LIGHT drops: it drops STOPS, not output (phase markers, evidence grades, Rule 6 table still show; 2026-09-27); this tree is autonomous. One `advisor()` at goal start and end; one three-lens panel once when the POC works, on a frozen commit. [CLAUDE.md Process]

## The gate map (what each proves)
| Gate / job | Proves | Where |
|---|---|---|
| CI job `licences` | every gate script's OWN test runs first (so a gate that stopped detecting cannot stay green), then the gates | ci.yml:18-66 |
| `dependency-licences.php` | lock files hold only permitted licences (five lists, each a maximum); web `third-party/<name>/COMPONENTS.json` | Makefile gate-licences |
| `spdx-headers.sh`, `executable-bits.sh` | SPDX header on every `git ls-files` file; scripts are 100755 (fileMode=false clones stage 100644 and fail a sibling job) | ci.yml comment |
| `icon-buttons-named`, `outcomes-as-toasts`, `icons-declared`, `design-tokens`, `tour-anchors` | web a11y/UX invariants by grep over source (not rendered) | gates dir |
| `permission-labels`, `setting-labels`, `planned-module-labels`, `presentation-settings-parity`, `stored-items` | label/key parity (fr+en), API-vs-SPA keys, cookie/storage declaration both ways; each carries floors | SPEC §7 2026-09-20/22/26 |
| `version-pins.sh`, `compose-log-rotation.sh` | every copy of a pin agrees (postgres, PHP, extensions, Node, Symfony minor, Angular major); every service rotates logs | docs/UPDATE.md |
| infra tests: logrotate, forwarded-proto, live-proxy | self-host logrotate; nginx proxy header trust; live proxy | Makefile |
| job `api` = `make gate-api` (`composer gate` in the `tools` container) | php-cs-fixer check -> PHPStan level max (warmed TEST container, no baseline) -> PHPUnit (Unit/Integration/Functional/Architecture; deprecation/notice/warning all fail); the OpenAPI document is exported by `make api-openapi`, which `gate-web` runs itself | r2-tech §7 |
| job `web` = `make gate-web` (`npm run gate` in the `web-tools` container) | api:types -> ng lint -> prettier -> Vitest (incl. i18n parity spec) -> build; self-contained: it regenerates the OpenAPI document first | r2-tech §7 |
| job `prod-image` | prod image check (`production-image.php`), `/api/health` ok, `/api/auth/me` 401 JSON | ci.yml:136-153 |
| job `e2e` (needs licences, api, web) | real browser through nginx -> FrankenPHP -> Postgres; 3 shards, each own stack+DB, serial inside, `fail-fast: false` | ci.yml:162-199 |
- `make gate` = gate-licences + gate-api + gate-web, NOT e2e. `make e2e` needs the full stack; `make up` is LIVE (source mounted), `make up-images` = images exactly as CI. gate-licences runs 15 gate scripts + 16 gate tests + 3 infra tests (production-image.php is the `prod-image` job's). [Makefile 78-115]

## Standards and rules
| Rule | Applies when | Source | Last checked |
|---|---|---|---|
| Permitted for distributed code: MIT, Apache-2.0 (also `WITH LLVM-exception`), BSD-2/3, ISC, 0BSD, MIT-0, CC0-1.0, BlueOak-1.0.0, Unicode-3.0 | any dependency or vendored file | SPEC §7 2026-09-09..23 | 2026-10-02 |
| Dev-only exceptions: MPL-2.0 (axe), Python-2.0 (tooling), CC-BY build data; OFL-1.1 for font FILES only, with a LICENSE beside | dev tooling; never runtime | SPEC §7 2026-09-09/13 | 2026-10-02 |
| Refused for licence, not technique: PrimeNG (PrimeUI), Mercure hub (AGPL), Transloco/ng-openapi-gen (argparse Python-2.0), zbar (LGPL) | proposing a dependency | SPEC §7 | 2026-10-02 |
| THIRD-PARTY-NOTICES.md is generated (`make notices`) in the change that adds a dependency | dependency change | Makefile:134 | 2026-10-02 |
| Held back deliberately: TypeScript 7, Vitest 5, material-color-utilities 0.4.0 (peer ranges); "latest everywhere" has these exceptions | upgrades | SPEC §7 2026-09-13 | 2026-10-02 |
| A discovery that yields nothing must red; a floor set to exactly what passes is not a floor | writing/changing a grep gate | SPEC §7 2026-09-20 | 2026-10-02 |
| Every gate has `scripts/gates/tests/<x>.test.sh`, run before the gate | adding a gate (also add it to ci.yml AND Makefile) | r2-tech §4; ci.yml | 2026-10-02 |
| Master only, plain `git push`, no force, no PR; one exception: builder worktrees on a LOCAL never-pushed branch | commits | CLAUDE.md Git; SPEC §7 2026-09-25 | 2026-10-02 |
| Keep docs/START.md, docs/UPDATE.md true; no document holds version numbers (`make versions`) | changing what they describe | SPEC §7 2026-09-19 | 2026-10-02 |
| Ruling entries: `ASSUMED`/`DECIDED (revisit)`/`TAKEN OVERNIGHT` are not user rulings until ratified; append at END of the log | recording decisions | SPEC §7 2026-09-13/24; memories | 2026-10-02 |

## Tools of the trade (as configured)
- PHPUnit ^13 + DAMA (each test in a rolled-back transaction; rate-limiter pool, `var/test-files`, session dir are NOT rolled back); hand-written `InMemory*`/`Fake*` doubles, not mocks. [r2-tech §4]
- PHPStan `level: max` over src AND tests, no baseline, 0 ignores; needs `cache:warmup --env=test` first. [phpstan.dist.neon via r2-tech §7]
- Architecture tests are lint: layer dependencies, `company_id` on every business table, audited use cases transactional, permission constants in catalogue. [r2-tech §7]
- Vitest via `ng test` (jsdom: proves logic, not layout or real focus/overlay behaviour); Playwright + `@axe-core/playwright` (workers 1, retries 0; design and gallery configs on :4200). [r2-tech §1]
- Visual surface: a rendered change needs before/after screenshots from the real app, not jsdom (global Rule 6). The gallery spec (viewports 1440x900, 1280x720, 390x844) exposes clipped/off-screen controls. [memories §3]
- Reproduce a CI failure on the same input: `gh run view <id> --json conclusion,jobs`, then Playwright report artifact `playwright-report-<shard>`. [ci.yml:199]
- Frozen-machine recovery: `git stash create` + `git update-ref refs/recovery/<date>`; delete the ref after green CI. [SPEC §7 2026-09-26]

## What "good" looks like
- Change fully staged, gates run on the staged tree, committed alone (never verification chained to commit), CI green on that SHA across ALL jobs, e2e included for UI flows.
- Completion note: certified by execution vs not, one sentence each; sabotage restored byte-for-byte and the specs it ran named.
- A bug fixed by a test that was seen RED first for the stated reason; a UI interaction rule gets an e2e that does the thing twice in one visit (unit specs open one form per test and cannot see form-stays-open rules). [SPEC §7 2026-10-02]

## Reading CI honestly
- A red run is a LOWER BOUND: `needs: [licences, api, web]` means e2e is skipped when an earlier job reds, so a green e2e was never judged on a red head. Read EVERY job's conclusion. [ci.yml:165]
- `gh run watch --exit-status` has returned 0 on a red run (2026-09-23: licences red, e2e skipped, master red two commits). Use `gh run view <id> --json conclusion,jobs`. [memories §2]
- `gh run list --commit` needs the FULL 40-char SHA: `SHA=$(git rev-parse HEAD)` in the same command, pin it before any loop, compare `headSha`; a padded/invented SHA is empty forever with exit 0. [memories §2-3]
- Concurrency is `cancel-in-progress` per ref: a push cancels the previous run; a "cancelled" e2e belongs to the superseded commit. Judge the run of the commit that carries the specs. [ci.yml:12]
- `timeout 580 gh run watch` in the FOREGROUND; never poll a background job from a second call. [memories §3]
- Local pass at load 20+ is no guarantee; local FAIL is worth reading (two defects in a minute vs two ~14-min CI rounds). [memories §3]

## When a gate lies (classic failure modes, with detection)
- Grep gate checks leaves not the branch / discovery yields nothing / keys built by interpolation are invisible -> detection: sabotage the guarded thing and see red; a floor must exceed what one half alone produces. [SPEC §7 2026-09-20/22]
- Gate enumerates `git ls-files` -> blind to untracked files; run meta-suites AFTER `git add`. [memories §3]
- Sabotage "passes": the batch include list omitted the spec that covers it, or the mutant never type-checked/landed -> absent `Tests N passed` tally is the tell. Never run a sabotage batch in parallel with the gate it mutates. [SPEC §7 2026-09-21]
- Guard test vacuously green because the guarded animation finished before the scan; axe tests set their own timeout and reset scheme inline, never in `afterEach`. [SPEC §7 2026-09-16]
- A contrast "defect" can be phantom: refute it with a sabotage that worsens the number (10.92 vs 14.42). [SPEC §7 2026-09-16]
- Certification covered behaviour, never usability: a lens cannot see what the developer cannot use; walk the running stack. [SPEC §7 2026-09-21]
- e2e on one shared DB: assume non-empty lists, run-unique names, cleanup via in-page fetch that throws on `!ok`. [r2-tech §8.15]
- Entity change with no migration: no schema-diff check exists (Unverified beyond a grep of Makefile/ci.yml/tests); `composer test` (in the `tools` container) migrates from files. [r2-tech §8.6]
- Local PHP is ZTS DEBUG GCOV: exit 134 `zend_hash.h:1658` is not a test failure; rerun once, investigate only a recurring abort on the same test; CI runs release PHP. [memories §3]
- Stale e2e container: web serves a STATIC build; `docker compose up -d --build web api` first, images build from the WORKING TREE (a worktree-at-HEAD bring-up proves a clean clone). [memories §3]
- Plain `docker compose up` forgets what `make up` exports (LIVE override via Makefile `COMPOSE_FILE`, never `.env`); use `make up`/`make up-images`. [SPEC §7 2026-09-23]

## Machine: what NOT to run in full
- The box runs at load 20-31 on 8 cores with swap near full: full PHPStan/unit/e2e locally cannot certify; run targeted specs, let CI arbitrate. [SPEC §7 2026-09-25/26]
- `make gate-licences` cannot finish in one 600 s call (`setting-labels.sh` ~179 s): run tail gates one by one. [memories §3]
- `run_in_background` tests get OOM-killed; the same command passes in foreground under `timeout 580`. rtk condenses piped phpunit output (`grep '^OK'` matched nothing; read a log file). [memories §3]
- Full-tree runs get SIGKILLed under load and can corrupt incremental caches (global machine note). [~/.claude CLAUDE.md]
- Parallel agents: max two builders on disjoint files in git worktrees, running only the specs they touch; Claude integrates and runs the whole suite and gates. [SPEC §7 2026-09-25]
- Reviewer lenses froze the parent twice: lens writes to `var/claude/raw/<round>-<lens>.md` incrementally and returns ONE LINE; `/compact` before a panel; a lost lens is completed inline and disclosed; such a round is a floor. [memories §3]
- `bash Makefile` once ran up/down; never pass a Makefile to bash, and after odd Docker output check `docker compose ps`. [memories §2]
- Toolchain PATH (phpbrew php-master vs 8.5, nvm Node 26), Playwright IPv6 install, Write-tool-for-source: already in CLAUDE.md; not repeated. [CLAUDE.md:166-169,237,329]

## Evidence surfaces (what counts as proof, by level)
| Level | Counts as evidence | Does NOT prove |
|---|---|---|
| Unit (PHPUnit, Vitest) | named test run with tally, seen RED first for the stated reason | wiring, layout, worker-mode state, anything the in-memory fake hides |
| Integration/Functional | real container + Postgres test DB (`composer test`, in the `tools` container) | DB state outside DAMA (rate-limiter, files, sessions) |
| Architecture | `tests/Architecture` green; sabotage a forbidden import to see red | runtime behaviour |
| Repo gates | `make gate-licences` tail lines per gate + the gate's own test + a sabotage | a grep gate over interpolated keys or an unreached branch |
| Licence/SPDX | `dependency-licences.php` (`--dump-rules` lists the rules) and `spdx-headers.sh` on the STAGED tree | a runtime-reachable licence not in a lock file (vendored wasm: COMPONENTS.json) |
| a11y | axe via Playwright on the running stack; contrast measured, sabotage to refute | manual screen-reader use |
| e2e | `make e2e` or CI `e2e` job, all 3 shards green, on the commit SHA | usability (developer walkthrough) |
| Prod/clean-clone | CI `prod-image`; `git worktree add <tmp> HEAD` bring-up | -- |
| Visual | before/after screenshots of the real rendered result | -- |
- Unexercised dimensions are named in the completion note; never "all green" over a skipped e2e. [CLAUDE.md]

## Reviewer lenses
1. **Gate honesty**: does each relevant gate go RED when the guarantee is broken (sabotage landed, right assertion, restored byte-for-byte)? Is a floor real?  2. **CI truth**: full-SHA run, every job's conclusion, no cancelled/skipped job read as green; what was certified by execution vs not?  3. **Clean-clone and licence**: would a worktree at HEAD bring up, did anything enter the tree the licence rule forbids, were all copies of a pin and the notices updated?

## Vocabulary
| Term | Meaning |
|---|---|
| gate | script under scripts/gates plus its own test, wired in ci.yml AND Makefile |
| floor | minimum count a discovery must exceed so an unmatched pattern reds |
| LIVE / images | `make up` (source mounted) vs `make up-images` (CI-identical) |
| shard | one of 3 e2e runners, own stack and DB |
| certified by execution | proven by a command run and its output read, not reasoning |
| ASSUMED / DECIDED (revisit) | unratified decision tags, not user rulings |

## Canonical sources
- twes-in: .github/workflows/ci.yml, Makefile (gate targets), CLAUDE.md (Process, Git, Lessons), docs/SPEC.md §5 and §7, docs/START.md, docs/UPDATE.md, LICENSING.md (ci.yml/Makefile read 2026-10-02).

## Changes vs v2
- Gate map rewritten as a what-it-proves table; verified against ci.yml/Makefile (15 gate scripts, 16 gate tests, 3 infra tests; job names licences/api/web/e2e/prod-image; v2 listed a stale 17-script count).
- Added e2e sharding (3), needs-chain skip semantics, concurrency cancel, prod-image job.
- Added rules table with licence refusals, held-back majors, floor rule, builder-worktree exception.
- Added evidence-by-level table (unit..visual) and tools as configured (DAMA limits, PHPStan max, axe).
- Added machine section (load, OOM, gate-licences timeout, ZTS abort 134, lens freeze) from r2-memories.
- Removed v2 gotcha rows already in CLAUDE.md Lessons/Process (git grep, -q, pipes, mutant restore, cwd drift, PATH, IPv6) to avoid restating.
- Process note updated: LIGHT drops stops, not output (2026-09-27); autonomous tree.
