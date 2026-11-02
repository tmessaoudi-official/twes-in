---
name: domain-quality-gates-ci
description: Use when a task involves running, changing or reading gates or CI, certifying work (unit/integration/architecture/e2e/a11y, sabotage checks), licence or SPDX checks, make targets, Docker bring-up or load on this machine in twes-in. What each gate proves, what counts as evidence, how to read CI honestly, when a gate lies.
---
Review date: 2026-11-02 22:20   Validation mode: advisory   Core: .claude/rules/expertise-core.md

## Roles and mental models
- **Release engineer**: done = CI green on the commit itself (SPEC §5; CLAUDE.md Process). Local runs find defects fast; CI arbitrates. [CLAUDE.md]
- **Licence auditor**: a dependency is a legal event; one copyleft or unlicensed package in a lock file kills the commercial licence. [ci.yml comment]
- **Mutation tester**: a green suite proves the code passes its tests; only a sabotage that landed, parsed and reddened the right assertion proves they would notice. Evidence only against the tests it ran. [SPEC §7 2026-09-21]
- **Honest reporter**: every completion note says what was and was NOT certified by execution. [CLAUDE.md Process]
- Process LIGHT drops stops, not output; this tree is autonomous. One `advisor()` at goal start and end; one panel of four lenses in two waves of two on a frozen commit, reviewers write to `var/claude/` and return one line. [Source: CLAUDE.md Process, which wins over the old three-lens wording]

## The gate map (what each proves)
| Gate / job | Proves | Where |
|---|---|---|
| CI `licences` | each gate script's OWN test first (a gate that stopped detecting cannot stay green), then the gates; also `make audit` | ci.yml:34 |
| `dependency-licences.php` | lock files hold only permitted licences (five lists, each a maximum); `third-party/<name>/COMPONENTS.json` | gate-licences |
| `spdx-headers.sh`, `executable-bits.sh` | SPDX on every `git ls-files` file; scripts 100755 | ci.yml comment |
| ~60 grep gates (a11y, i18n, tokens, direction, refusals, compose, secrets, migrations, signatures...) | source invariants by grep, not rendered; each with floors | CLAUDE.md "Where things live" lists each |
| CI `api` = `composer gate` (`tools`) | static first (php-cs-fixer, PHPStan max, no baseline), then PHPUnit on paratest (deprecation/notice/warning fail, api/phpunit.dist.xml:7-9), each worker its own DB/cache/share dir | [Ruled: user 2026-10-14, docs/SPEC.md:5182; 2026-10-26, :5675] |
| CI `web` = `npm run gate` (`web-tools`) | api:types, lint, typecheck of e2e and Playwright configs, prettier, then Vitest (i18n parity), build | [Ruled: user 2026-10-11, docs/SPEC.md:5017]; web/package.json:25-26 |
| CI `prod-image` (needs licences) | `production-image.php`, `/api/health`, `/api/auth/me` 401 | ci.yml:93 |
| CI `e2e` (needs licences, api, web) | real browser through the stack; 3 shards, own stack+DB each; `@phone` also on WebKit | ci.yml:135; [Ruled: user 2026-10-21, docs/SPEC.md:5577] |
| CI `publish` | needs all five jobs; pushes images by commit | ci.yml:189 |
| Other workflows | decision-stamps, deploy-check, image-scan, monitor-check, restore-drill (weekly, by hand, on backup-file pushes), staging-check | [Source: ls .github/workflows 2026-11-02]; [Ruled: user 2026-10-12, docs/SPEC.md:5095] |
- `make gate` = static halves, then suites, then licences; NOT e2e and never boots the app (`app-config.spec.ts` covers the boot path; before landing work built elsewhere run one e2e on an image of the result). [Source: Makefile:189; CLAUDE.md lesson 2026-11-01]
- 66 gate scripts, 69 gate tests in `scripts/gates/`, each test through `tests/no-leftovers.sh`. [Source: ls 2026-11-02; CLAUDE.md lesson 2026-10-09]

## Standards and rules
| Rule | Applies when | Source |
|---|---|---|
| Permitted licences and dev-only exceptions: CLAUDE.md Licensing invariant 3 (not repeated) | any dependency | CLAUDE.md |
| Refused for licence: PrimeNG, Mercure hub, Transloco, ng-openapi-gen, zbar, QZ Tray, MinIO (AGPL; SeaweedFS stands in for CI drills) | proposing a dependency | core §2; [Ruled: user 2026-10-12, docs/SPEC.md:5094] |
| Everything on its latest stable, majors included, one ecosystem a commit, each through gate, e2e, prod image, deploy check; TypeScript 7 held; a changed licence stops the bump and is asked | upgrades | [Ruled: user 2026-10-16, docs/SPEC.md:5310] |
| THIRD-PARTY-NOTICES.md generated (`make notices`) in the change adding a dependency | dependency change | CLAUDE.md "Where things live" |
| Keep docs/START.md, docs/UPDATE.md true; `make versions` is the truth for versions | changing what they describe | CLAUDE.md "Where things live" |
| Dependabot alerts only, no PR (one-copy bumps red version-pins) | dependency alerts | [Ruled: user 2026-10-12, docs/SPEC.md:5062] |
| A discovery yielding nothing must red; a floor set to exactly what passes is not a floor | grep gates | SPEC §7 2026-09-20 |
| Every gate has `scripts/gates/tests/<x>.test.sh`, run first; wired in ci.yml AND Makefile; a class findable mechanically gets a gate | adding a gate | CLAUDE.md "A fix is a class"; [Ruled: user 2026-10-26, docs/SPEC.md:5665] |
| Master protected: seven required checks, no force/delete, developer admin bypass | pushes | [Ruled: user 2026-10-12, docs/SPEC.md:5064] |
| Other lanes: builders on local never-pushed branches; cloud `claude/...` branches landed by `land.sh`; `release/**` where a patch for the shops is built (`release-lane.sh`; e.g. the interim TN 2027 patch if stable is not out by 2026-12-15) | branches | CLAUDE.md Git and gates; [Ruled: user 2026-10-31, docs/SPEC.md:5747] |
| Commits since the newest tag carry a good GPG signature by `infra/onprem/release-key.asc` (`signed-commits.sh`); no cosign this release | commits, tags | CLAUDE.md gates; [Ruled: user 2026-10-31, docs/SPEC.md:5755 Q16] |
| `ASSUMED`/`DECIDED (revisit)`/`PROVISIONAL`/`TAKEN OVERNIGHT` are not rulings; append at END; stamps checked by `decision-stamps.sh` | recording decisions | core §5; CLAUDE.md gates |

## Tools of the trade (as configured)
- PHPUnit + DAMA (rolled-back transaction; rate-limiter pool, files, sessions are NOT); hand-written fakes, not mocks. [r2-tech §4]
- PHPStan max over src and tests via `composer stan` (warms the test container). [CLAUDE.md lesson 2026-09-22]
- `make check FILES=...` (one line, no newline) before every gate: format, cached PHPStan, the named files' specs only; add every test that `git grep -l`s the changed directory or contract. [Ruled: user 2026-10-15, docs/SPEC.md:5253; 2026-10-26, :5675; CLAUDE.md lessons 2026-10-18, 2026-10-28]
- `make mutants SPEC=<file>`: our own runner (landed and tally checks, plain-cp restore, cmp), not Infection or Stryker; never beside a gate. [Ruled: user 2026-10-15, docs/SPEC.md:5254; Makefile:185]
- `make stress`: race tests (`api/tests/Concurrency`) on `twes_test_stress`, under the gate lock. [Source: Makefile:407]
- Vitest (jsdom: logic, not layout); Playwright + axe through `axeAtRest`. Visual changes need before/after screenshots of the real app (global Rule 6).
- CI failure: `gh run view <id> --json conclusion,jobs`, then artifact `playwright-report-<shard>`. [ci.yml:180]

## What "good" looks like
- Change staged, `make check` on it, gated as a snapshot, committed through `commit-gated` (never verification chained to commit), CI green on that SHA across ALL jobs, e2e for UI flows. [CLAUDE.md Process; docs/SPEC.md:5555]
- Completion note: certified by execution vs not, one sentence each; sabotage restored byte-for-byte, the specs it ran named.
- A bug fixed by a test seen RED first for the stated reason; a UI interaction rule gets an e2e doing the thing twice in one visit. [SPEC §7 2026-10-02]

## Snapshot gate, builders, hunters
- `make gate-snapshot` gates the STAGED tree as an unnamed commit in `.claude/worktrees/gate` (DBs `twes_test_gate*`, the one gate lock) while work goes on; `make commit-gated MSG=<file>` commits exactly the last green tree (refused when red or master moved); a red one is fixed on top. Every postgres start passes `--no-recreate`. Local e2e stays serial, touched specs only. [Ruled: user 2026-10-21, docs/SPEC.md:5555]
- `commit-gated` signs as `git commit` would and refuses on a failed signature (docs/START.md § 7); any other plumbing commit (`git commit-tree`) never reads `commit.gpgsign`: pass `-S`, then check `git log --format=%G?`. [CLAUDE.md lesson 2026-10-24; docs/START.md:523]
- One full gate per two or three slices; nothing pushed before the full gate and CI are green. [Ruled: user 2026-10-20, docs/SPEC.md:5503]
- At most 2 builders + 1 read-only hunter, on disjoint units of half a day or more; heavy runs serialized by `/tmp/twes-in-gate.lock`; while `/tmp/twes-in-gate.main-first` exists builders run only `make check` and their files' tests (max 3 workers); read `git log master..<branch>` of every builder before handing a row. [Ruled: user 2026-10-26, docs/SPEC.md:5681; 2026-10-28, :5709; CLAUDE.md lesson on flock]
- No cloud sessions for the stable release; a review lens at the end of each phase. [Ruled: user 2026-10-31, docs/SPEC.md:5752]
- The developer's local stack is Claude's e2e stack, left an empty platform (`make up-images SEED=0`). [Ruled: user 2026-10-28, docs/SPEC.md:5709; CLAUDE.md lesson 2026-10-27]
- After the 2026-10-26 release's tag (an example of the cleanup, not a standing rule): its worktrees, branches but master, builders' test DBs went without asking item by item, each checked landed or superseded, listed in the report. [Ruled: user 2026-10-26, docs/SPEC.md:5683]
- Workflow hunt: `lifecycle-`, `persona-`, `freshness-`, `mistake-recovery-reviewer` (read-only, `.claude/agents/`), before a release and whenever a change adds a status, a role, a write or an input; a gate wherever a class they find is mechanical. R32 swept every recorded class first, then ran the lenses one at a time (SPEC:5731); the 2026-10-26 hunt two waves of two (:5675). [CLAUDE.md Process; Ruled: user 2026-10-26, docs/SPEC.md:5665; Verified: ls .claude/agents]
- Release 26's shape (an example, not a standing rule): rounds until no new P0/P1, cap 3, P2/P3 as rows, paced by fixes. [Ruled: user 2026-10-22, docs/SPEC.md:5600, :5624]

## Release checks
- Before each tag (core §3, SPEC:5708): `make onprem-check` (Makefile:153: install, both doors, a migration release rolled back on its schema, restore; ports 80/443, ~10 min) and `make update-rehearsal FILES=...` (Makefile:155: FROM tag via `twes update`, the shop's files imported, TO taken, every table read back; never beside onprem-check).
- One release carries everything with a tested rollback; no patch tag before the stable. [Ruled: user 2026-10-31, docs/SPEC.md:5737, :5746]

## Reading CI honestly
- A red run is a LOWER BOUND: e2e is skipped when an earlier job reds. Read EVERY job's conclusion. [ci.yml:135]
- `gh run watch --exit-status` has returned 0 on a red run; `gh run list --commit` needs the FULL SHA, `SHA=$(git rev-parse HEAD)` in the same command. [memories]
- `cancel-in-progress`: a push cancels the previous run; hold a push until the run reports, keep working meanwhile. [ci.yml:27; Ruled: user 2026-10-21, docs/SPEC.md:5555 (no waiting on CI); Observed: memory keep-working-while-ci-runs]
- Local pass at load 20+ is no guarantee; a local FAIL is worth reading. [memories]

## When a gate lies (classic failure modes, with detection)
- Grep gate checks leaves not the branch / discovery yields nothing / keys built by interpolation -> sabotage the guarded thing; a floor must exceed one half alone. [SPEC §7 2026-09-20/22]
- Gate enumerates `git ls-files` -> blind to untracked files; stage first. [CLAUDE.md lessons]
- Sabotage "passes": include list omitted the covering spec, or the mutant never type-checked -> absent tally is the tell. [SPEC §7 2026-09-21]
- Guard vacuously green because the guarded animation finished before the scan; axe tests set their own timeout and reset the scheme inline, never in `afterEach`. [SPEC §7 2026-09-16, docs/SPEC.md:815 DECIDED]
- Certification covered behaviour, never usability: walk the running stack; hunters drive the real screens. [SPEC §7 2026-09-21; CLAUDE.md Process]
- e2e on one shared DB: run-unique names, cleanup via in-page fetch that throws on `!ok`. [r2-tech §8.15]
- Entity change with no migration: `SchemaInSyncTest` (api suite) reds when the mapping and the migrated schema differ; `additive-migrations.sh` bounds what a migration may drop after the newest tag. [api/tests/Integration/Shared/SchemaInSyncTest.php; SPEC §7 2026-09-14 DECIDED, docs/SPEC.md:706; CLAUDE.md gates]
- A contrast "defect" can be phantom: refute it with a sabotage that worsens the number. [SPEC §7 2026-09-16]
- Five-second cases time out beside a gate: one page per Vitest case (`it.each`), never a longer timeout or retry. [CLAUDE.md lesson 2026-10-21; Ruled: user 2026-10-26, docs/SPEC.md:5675]
- Stale e2e container: images build from the WORKING TREE; a worktree-at-HEAD bring-up proves a clean clone. [CLAUDE.md lesson 2026-09-19]

## Machine: what NOT to run in full
- Load 20+ on 8 cores: nothing heavy beside a gate; `make gate`, `make check`, `make mutants`, `make stress`, onprem-check and update-rehearsal never side by side. [CLAUDE.md Process; Makefile help]
- `make gate-licences` outran one 600 s call. [Observed: memories]
- Never `timeout` around `make tools`/`make web-tools`; `free -h` first. [CLAUDE.md lesson 2026-10-08]
- No PHP, Composer or Node on the host: CLAUDE.md "Run everything in Docker". [Ruled: user 2026-10-02]
- Full-tree runs get SIGKILLed under load and can corrupt caches (global machine note); rtk condenses piped phpunit output: read a log file or `rtk proxy`. [~/.claude CLAUDE.md; CLAUDE.md lesson 2026-09-21]
- `bash Makefile` once ran up/down; never pass a Makefile to bash. [memories]

## Evidence surfaces (what counts as proof, by level)
| Level | Counts as evidence | Does NOT prove |
|---|---|---|
| Unit | named test run with tally, seen RED first for the stated reason | wiring, layout, worker-mode state |
| Integration/Functional | real Postgres via `composer test` | state outside DAMA |
| Architecture | `tests/Architecture` green; sabotage a forbidden import | runtime behaviour |
| Repo gates | the gate's line + its own test + a sabotage | interpolated keys, an unreached branch |
| Licence/SPDX | `dependency-licences.php` (`--dump-rules`) and `spdx-headers.sh` on the STAGED tree | a licence outside a lock file (vendored wasm: COMPONENTS.json) |
| Boot | one e2e on an image of the result | -- |
| a11y | axe on the running stack | screen-reader use |
| e2e | all 3 shards green on the commit SHA | usability |
| Prod/clean-clone | CI `prod-image`; `git worktree add <tmp> HEAD` bring-up | -- |
| Release | onprem-check + update rehearsal on that commit, pasted | -- |
| Visual | before/after screenshots of the real app | -- |

## Reviewer lenses
1. **Gate honesty**: does each relevant gate go RED when the guarantee breaks (landed, right assertion, restored byte-for-byte)? Is a floor real?  2. **CI truth**: full-SHA run, every job's conclusion, nothing cancelled/skipped read as green; certified vs not?  3. **Clean-clone, licence, signature**: would a worktree at HEAD bring up; anything the licence rule forbids; every commit signed; pins and notices updated?

## Vocabulary
| Term | Meaning |
|---|---|
| gate | script under scripts/gates plus its own test, in ci.yml AND Makefile |
| floor | minimum count a discovery must exceed |
| snapshot / commit-gated | the staged tree gated in its own worktree / committing exactly that green tree |
| builder / hunter | worktree writer on a local branch / read-only reviewer writing to var/claude |
| LIVE / images | `make up` (source mounted) vs `make up-images` (CI-identical) |
| shard | one of 3 e2e runners, own stack and DB |
| certified by execution | proven by a command run and its output read |

## Canonical sources
- .github/workflows/*.yml, Makefile, CLAUDE.md (Process, Git, gates list, Lessons), docs/SPEC.md §5 and §7, docs/START.md § 7 and § 16, docs/RUNBOOK.md, LICENSING.md.

## Changes vs 2026-10-09
- See var/claude/expertise-refresh/packs/domain-quality-gates-ci.changes.md.
