# CLAUDE.md — twes-in

> **Every decision is weighed against the North star at the top of `docs/SPEC.md` § 1; a choice that
> contradicts it is raised with the developer before it is made.**
>
> `docs/SPEC.md` says WHAT twes-in is, what is ruled, what exists and what is next. READ IT
> FIRST. This file says HOW work is delivered here. On conflict with the global framework
> (`~/.claude/CLAUDE.md`) this file wins; on conflict about the product, the spec wins.

twes-in is an invoicing SaaS (Symfony API + Angular admin over PostgreSQL), a clean-room
reimplementation inspired by Invoice Ninja, AGPL-3.0-or-later plus a commercial licence.

## Process — LIGHT (developer ruling, 2026-09-09)

The previous process (sixteen custom gates, per-commit mutants, review panels, evidence
tables, essay gotchas) was retired with the reset. What applies here:

- **Announce, then build.** One line stating the size (Small / Medium / Large) and a plan of at
  most five bullets. Phase markers, evidence grades and the Rule 6 evidence table still SHOW —
  output parity (developer ruling 2026-09-27, which supersedes this line's 2026-09-09 "no phase
  markers, no evidence tables"); what LIGHT drops is the stops. Stops follow the global
  `~/.claude/CLAUDE.md` § Mode: this tree is autonomous (ask-human gate bypassed), so an ambiguity
  takes the recommended option, logged as `ASSUMED (review)` in the plan's Decisions Log. Ask only
  for a user-visible product decision or anything that would weaken an invariant below, always
  through `AskUserQuestion` with the recommended option first and a visible "none of these" escape.
- **Official best practices, for every tool** (developer ruling, 2026-09-17): Symfony, PHP, Monolog, API Platform,
  Doctrine, Angular, TypeScript and the rest are used as their own documentation recommends; a departure is recorded
  in `docs/SPEC.md` § 7 with its reason, and a gap found is fixed, not worked around.
- **TDD is not optional.** Failing test first for every behaviour; money arithmetic, tax
  components and state transitions always. Tests are executed, and their output is pasted.
- **Done means CI green** against the definition of done in `docs/SPEC.md` § 5. Never commit
  red. Never chain a verification step onto `git commit` through a pipe or `&&`.
- **Gate a snapshot, keep working** (developer ruling, docs/SPEC.md § 7): stage, `make gate-snapshot` in the background,
  go on with the next thing here (never in the gate's worktree), then `make commit-gated MSG=<file>`, which commits only
  the tree that went green. A red one is fixed on top and gated again. `make e2e` stays serial, the touched specs only.
  Before gating, `make check FILES=<the staged files>` (seconds to minutes), and nothing heavy beside a gate: its web
  suite's five-second cases time out under load (developer ruling, 2026-10-26 16:39; 6 of the day's 16 runs went red for
  what that check or a quiet machine would have spared).
- **One `advisor()` call at goal start and one at goal end**, not per commit. One reviewer panel
  once, when the POC works, against a frozen commit, of four lenses in two waves of two (`.claude/agents/`:
  domain correctness, tenancy and security, completeness, usability and misuse); reviewers write to
  `var/claude/` and return one line; spawn them unnamed. A **workflow hunt** of four more lenses (`lifecycle-reviewer`,
  `persona-reviewer`, `freshness-reviewer`, `mistake-recovery-reviewer`: each state's actions, each role's jobs, each
  write's stale viewers, each input's way back) maps the code before a release and whenever a change adds a status, a
  role, a write or an input; hunters driving the real screens follow the push (docs/SPEC.md § 7, 2026-10-26 00:07).
- **Say what was and was not certified by execution** in a goal's completion note, in one
  sentence each. "Tests pass" is not a claim about a container coming up.
- **A fix is a class** (developer ruling, docs/SPEC.md § 7): every fix, decision or finding names its class (a silent
  result, a stale screen, a field not seen, an unclear error, an action missing where its subject appears), lists every
  member found, its search pasted in the plan, and fixes them in the same change or names each one left as a plan row. A
  gate is added where the class can be found mechanically, a lesson otherwise. Generalize when a second member exists or
  a principle covers it; a one-off is written down with why.
- **Deciding while the developer is away**: pick when a rule decides (never silent; every action wherever its subject
  appears, its context filled in and changeable; every outcome shown at once in every tab; guide but never choose a fiscal
  or legal answer; data over code; one canon; undo first; parameters over fixed values; a fix is a class); when none does,
  pick only if `advisor()` agrees, else park it and move on. Every pick is logged `ASSUMED (review)` naming its rule.
- Craft lessons go to the rules file of their area, `.claude/rules/{api,web,infra,gate-scripts}.md`, or under
  § "Lessons" below when they apply before any file is read (running a command, the stack, the gates, git); five
  lines at most, only when they change what to do next time. A rules file past about 300 lines, or these Lessons past
  about 150, is split or pruned.
- **Comments say why**, with no dates and no SPEC row numbers (developer ruling, 2026-09-27): the decision's record is
  § 7, found by the code's own words. Trim an old comment's dates and rows when its file is next edited, never in bulk.

## Licensing invariants — the cardinal rule

1. **Upstream code never enters this tree.** Invoice Ninja's backend and web UI are Elastic
   License 2.0; a translation of their code is a derivative work. Build from the contract, the
   behaviour and the standards (EN 16931, UBL, CII, Factur-X, Peppol, El Fatoora), never from
   the source. Reference clones live in `/tmp/xxx/**`, never in the working tree. One bounded
   exception (docs/SPEC.md § 7, 2026-10-04 08:04 and the audit of 2026-10-06): for the importer, Invoice
   Ninja's source may be read ONLY to learn what its exports and data hold (field names, file layout);
   nothing is copied, no code is translated, the source read is a fresh shallow clone of an upstream tag
   under `/tmp`, and what is learnt is written as data facts under `docs/research/`. The developer's own
   fork at `/stack/projects/invoiceninja` is another project: never read from, written to or copied from.
2. **Never reproduce, disable or reimplement a licence-key or branding gate** from upstream.
   Our own plan gating is ours and is fine.
3. **Every dependency is permissive and recorded** in `THIRD-PARTY-NOTICES.md` (generated from
   the lock files, checked by `scripts/gates/dependency-licences.php` in CI). Read the actual
   LICENSE file of anything whose lock entry is not a bare SPDX identifier: PrimeNG's whole family
   was refused at G0 because "SEE LICENSE IN LICENSE.md" turned out to be an eligibility-gated
   commercial licence with a bundled licence-key verifier. Permitted for
   anything distributed: **MIT, Apache-2.0, BSD-2-Clause, BSD-3-Clause, ISC, 0BSD, MIT-0,
   CC0-1.0, BlueOak-1.0.0, Unicode-3.0** (the last ruled 2026-09-23, for the barcode decoder's Unicode tables). Narrow dev-only exceptions: CC-BY-4.0 / CC-BY-3.0 build-time data;
   MPL-2.0 dev-only tooling (Angular's `lightningcss`); Python-2.0 dev-only tooling (`argparse` under
   `@hey-api/openapi-ts`, ruled 2026-09-09); OFL-1.1 vendored fonts; `Apache-2.0 WITH LLVM-exception` for libc++
   compiled into a vendored wasm (ruled 2026-09-22), whose components are listed in `web/src/third-party/`. "AGPL-compatible"
   is the wrong test: a copyleft dependency kills the commercial branch. Adding an identifier is
   a licensing decision, never a build fix.
4. **Copyright stays wholly owned**: no outside contribution without a CLA (none exists yet).
   New source files carry `SPDX-License-Identifier: AGPL-3.0-or-later`: php-cs-fixer adds it to PHP,
   `scripts/gates/spdx-headers.sh` checks PHP, TypeScript and shell.
5. **All branding is ours and configurable**; nothing hardcoded.
6. **When a licensing question is unclear, STOP and ask.** One-way door.

## Git

- Autonomous `git add`, `commit` and `push` are authorised for CI-green, self-contained work
  on **`master`**, the only pushed branch. Plain `git push`, never `-u`. No `--force`, no history
  rewrite of master, no pull request. Parallel writers (docs/SPEC.md § 7, 2026-10-07 19:34) work on
  local branches in their own worktrees, never pushed, which the main session rebases onto master.
- Identity, verified including case before the first commit of a session:
  `Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>`. **Never a `Co-Authored-By`
  or `Claude-Session` trailer**; the developer's ruling overrides the harness.
- Commit style: `feat:` / `fix:` / `refactor:` / `docs:` / `chore:` / `test:`, imperative.
- `deny` and `ask` permission rules stay empty, permanently (developer ruling, 2026-08-06).
- Message files for `git commit -F` use a session-scoped name; `head -1` the file and print
  `git status --porcelain` in the same command as the commit.
- **A cloud session** (`CLAUDE_CODE_REMOTE=true`, claude.ai) cannot sign, so it never lands on master: it commits and
  pushes only the branch its harness gave it (`claude/…`, `ccr-…`), which CI runs as it runs master; the
  `master-signed` ruleset refuses an unsigned commit there anyway. It runs `make check` on each change and `make gate`
  once before it says the branch is ready (the setup script starts Docker; its builds trust the agent proxy's CA, which
  `CCS_EXTRA_CA_FILE` names, through the optional BuildKit secret `extra_ca` of `compose.extra-ca.yaml`, never by switching
  TLS checks off), and ends with the line the developer runs on
  the PC: `land.sh /stack/projects/twes-in <branch>` (claude-code-setup), which re-signs every commit as the developer,
  strips any trailer and fast-forwards master. No tag, no release, no deploy from the cloud.
  The session must have **claude-code-setup selected as a second repository**: without it nothing installs the framework
  or sets `CCS_EXTRA_CA_FILE`, so `make check` fails on the proxy's certificate (`SELF_SIGNED_CERT_IN_CHAIN`). If
  `docker info` fails, follow `claude-code-setup/cloud/machine-notes.md` to restart the daemon; if it still fails, say
  which gate legs did not run and leave the verdict to CI.


- `docs/SPEC.md` — product, architecture, goals, Decisions Log, status block.
- `docs/START.md` — bring-up, sign-in, creating users, the seed, `make reset`, the checks; `docs/UPDATE.md` — every
  version pin, its copies and how to bump it (`make versions` prints the values). Keep both true when changing what they describe.
- `TODO.md` — the developer's own steps (the release, the shop's handover), ticked by the developer; Claude ticks only
  what it ran itself, and keeps each step's exact commands true.
- `LICENSING.md` — read before adding any dependency; `THIRD-PARTY-NOTICES.md` — generated by
  `scripts/notices/generate-third-party-notices.php`, never hand-edited; run it in the change that adds a dependency. A
  licence beyond a lock entry (a package shipping a second licence text) is declared in `scripts/notices/licence-overrides.json`,
  its text under `scripts/notices/texts/` (LICENSING.md § 2).
- `scripts/build-version.sh` — each part's build from git (`YYYY.MM.DD.N`, hash, « -dirty »), passed by the Makefile and CI
  to the images, shown in the footer (`docs/START.md` § 11); its test is `scripts/tests/build-version.test.sh`, run by `make gate-licences`.
- **The API** (`api/src/<Context>/…` and its modules, the demo dataset, `api/translations/`, the logs, the scheduler,
  the fiscal schemas fetch, and the API's gates) → `.claude/rules/api.md`, which loads when you read a file under `api/`.
- `scripts/gates/` — one script per invariant, each with its test. What the web's, the API's and the infra's gates hold
  is listed in `.claude/rules/web.md`, `api.md` and `infra.md`; those every change meets are here:
  - `dependency-licences.php` (five lists, each a maximum, `--dump-rules`; it also checks each `web/src/third-party/<name>/COMPONENTS.json`, what a shipped wasm compiles in, against the lock)
  - `spdx-headers.sh`
  - `executable-bits.sh`
  - `decision-stamps.sh` (no Decisions Log stamp later than the commit `git blame` names for it, or than now while uncommitted; a late one found afterwards gains « (stamp corrected: written at HH:MM, commit <sha>) »; CI's licence job checks out the whole history for it)
  - `signed-commits.sh` (every commit since the newest release tag carries a good signature by `infra/onprem/release-key.asc`, read into a keyring of its own, as CI holds no other; in the snapshot gate's worktree alone, `.claude/worktrees/gate`, its own unsigned snapshot commit is read as its parent; on a cloud branch or in a cloud session, never on master or `release/*`, the unsigned commits are listed as pending land.sh's re-signing, compose handing the tools container `GITHUB_REF_NAME` and `CLAUDE_CODE_REMOTE`)
  - `docs-run-lines.sh` (every `docker compose … run` in a code span or block of `docs/*.md` and `TODO.md` reads `< /dev/null` or a pipe, or says `<!-- run-input: <why> -->`; a floor of 6)
  `tests/` beside them is each gate's own test, run by CI before the gate, each through `tests/no-leftovers.sh`, which reds a test leaving anything in its TMPDIR.
- `docs/fiscal/<CC>.md` — sourced fiscal rules per country; `api/config/fiscal/<CC>.yaml` — the preset;
  `docs/fiscal/TN-accountant-packet.md` — every Tunisian rule the code applies, its source and place, for the accountant
  to confirm or correct (a dated snapshot: its line numbers are the tree's of that date).
- `docs/spec/pricing-vectors.json` — the calculator's fixture set.
- `api/config/languages.json` and its identical copy `web/src/app/shared/i18n/languages.json` — the language catalogue:
  each language's BCP 47 tag, name in itself, direction, script, CLDR plural forms, and whether the interface, the documents
  and the legal pages speak it. The API reads it through the `Languages` port (`Shared/Infrastructure/Intl/CatalogueLanguages`;
  `LanguageNames` reads a language as a person writes it), the web through `shared/i18n/languages.ts`; `CatalogueLanguagesTest`
  holds the two copies equal and `translation.yaml`'s locales to it, `setting-labels.sh` reads a language setting's choices
  from it, and the label gates and the API's text tests check every language it switches on. A mail or a printed document
  turns over through `text_direction(locale)` (`Shared/Infrastructure/Twig/LanguageExtension`), with logical CSS and every
  typed value in a `<bdi>`. Adding a language is an entry there and its texts, never code. Arabic's words follow
  `docs/i18n/ar-glossary.md`.
- **The web** (`web/src/app/…`, its `shared/` canon, the texts and their parts, and the web's gates, the five holding the
  API and the web together among them) → `.claude/rules/web.md`, which loads when you read a file under `web/`.
- `var/claude/**` — transient review output, gitignored.
- `.claude/settings.json` — `defaultMode: auto`, allow-list, empty `deny`, no `ask`; one
  `PostToolUse` hook (`.claude/hooks/lint-on-write.sh`) running `php -l` / `bash -n` on writes.
- `.claude/rules/expertise-core.md` (loads at session start; the area rules files beside it, `api.md`, `web.md`,
  `infra.md` and `gate-scripts.md`, load with their folders), `.claude/EXPERTISE-REFERENCE.md` (read by section) and the four
  `domain-*` skills in `.claude/skills/` — what a generic engineer gets wrong here, the evidence surfaces and a trigger -> lesson
  table routing to the packs. Curated from the recorded decisions, memories and docs, dated (`Review date:`); do not hand-append:
  put a lesson under § "Lessons" or in its area's rules file and flag it for the next refresh. `bash ~/.claude/bin/expertise-verify.sh --dir .` checks them
  against the tree.
- **Images, deploying, a shop's computer, the monitor, backups** (`infra/api/Dockerfile`, the `compose.*.yaml` layers,
  `scripts/{deploy,onprem,release,backup,monitor,staging}/`, and the compose, overlay, pin and release gates) →
  `.claude/rules/infra.md`, which loads when you read a file there or `docs/{START,RUNBOOK,UPDATE}.md`, `TODO.md`, `.github/`.
- `Makefile` — `make up` (live: compose.yaml + `compose.live.yaml`, the source mounted, API workers restarted by
  FrankenPHP's watcher, the web tier on `ng serve`; `make up-images` runs the built images as CI does; `make live-refresh`
  after a resource property change; docs/START.md § 2), web :8090, api :8091, mailpit :8092, postgres :5433, gotenberg :8094, `lan` :8443 a phone's HTTPS door on this machine's network address, `infra/lan/Caddyfile`; the one-shot `migrate` service
  (`infra/api/migrate.sh`) migrates as the owner and grants `twes_app`, the role api and worker connect as, then `seed`: operator `operator@twes.local` / `twes-operator-dev`, authenticator secret
  `JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP`, `make operator-code` prints its current code; Playwright signs the operator in once, `web/e2e/session.ts`; `web/e2e/axe.ts` is the one WCAG scan, waiting for a fresh toast, and `web/e2e/toast.ts` the announced toast), `make gate` (both halves' static checks first, `composer gate:static` and `npm run gate:static`, so a style or type error
  reds in about two minutes; then `composer test`, `npm run gate:tests` and the licences, each in its toolchain container, below; CI runs
  the same checks as `composer gate` and `npm run gate`; `npm run gate` starts by regenerating the types, and type-checks `e2e*/` and the Playwright configs through `tsconfig.e2e.json`, which Playwright itself never does; `composer test` migrates the test database first),
  `make e2e` (Playwright against the running stack; a scenario tagged `@phone` runs on WebKit, Safari's engine, as well as Chromium), `make fixtures` (the demo companies, appended after `seed`).
  These are exactly CI's jobs; run the gate chain AFTER `git add -A`, because the SPDX gate and
  `git ls-files` see staged files and a cached-only enumeration misses a brand-new one.
- **No PHP, Composer or Node runs on the host** (developer ruling, 2026-10-02: every project but `/stack` runs its
  stages in Docker). The host needs Docker, `make`, `bash` and `git`. Use the table below; never `composer …`, `php …`,
  `npm …`, `npx …` or `node …` on the host, and never prepend a phpbrew or nvm directory to `PATH`.

## Run everything in Docker

The toolchain is two images, `tools` (`infra/api/Dockerfile` stage `tools`: PHP 8.5, Composer, git, jq, perl, python3,
logrotate, the Docker CLI) and `web-tools` (`infra/web/tools.Dockerfile`: Node 26 and Playwright's system libraries),
run by `compose.yaml` under the `tools` profile as the host user, on the host's network, with the working tree mounted at
its own path and a temporary directory outside it (`/tmp/twes-in-tools-<uid>`). `docs/START.md` § 1 says why each of
those three is needed. Run them through `make`, which hands compose the uid, the path and the socket's group.

| Command | Service | What it replaces |
|---|---|---|
| `make gate` (`gate-licences`, `gate-api`, `gate-web`) | `tools`, `web-tools` | `make gate` on host PHP and Node |
| `make check` (`FILES=…`, `FIX=1`) | `tools`, `web-tools` | running php-cs-fixer, PHPStan, PHPUnit, prettier, eslint or `ng test` by hand on the files just changed; `make gate` stays the authority |
| `make stress` | `tools` (+ compose `postgres`) | running a race by hand in two terminals: the race tests (`api/tests/Concurrency`, `Race::run`) on their own database `twes_test_stress`, under the gate's lock; CI's api job runs it |
| `make mutants SPEC=…` | `tools`, `web-tools` | applying a sabotage by hand, running its test and restoring the file; it reads each tool's tally, so a mutant that does not compile is INVALID, not killed |
| `make gate-licences` | `tools` | the gate chain's `php` and `bash` lines, with `logrotate`, `jq`, `perl`, `python3`, `docker` |
| `make gate-stamps` | `tools` | `bash scripts/gates/decision-stamps.sh` alone; CI's `decision-stamps` workflow runs it on a push touching `docs/SPEC.md`, which `ci.yml` skips |
| `make gate-api`, `make test-api` | `tools` (+ compose `postgres`) | `cd api && composer gate`, `vendor/bin/phpunit` |
| `make gate-web`, `make test-web`, `make api-types` | `web-tools` | `cd web && npm run gate`, `npx ng test`, `npm run api:types` |
| `make api-openapi` | `tools` | `bin/console api:openapi:export` on host PHP |
| `make e2e`, `make gallery` (`E2E_ARGS=…`) | `web-tools` | `npx playwright test` and a host-installed Chromium |
| `make videos` | `web-tools` | filming the guides (`web/e2e-recorder/`, `playwright.recorder.config.ts`, docs/START.md § 12); refuses under load or beside a gate |
| `make notices`, `make versions` | `tools` | `php scripts/notices/…`, `bash scripts/versions.sh` |
| `make audit` | `tools`, `web-tools` | `composer audit`, `npm audit --omit=dev --audit-level=high`; CI's `licences` job runs it |
| `make php-lint FILE=…` | `tools` | `php -l`, what `.claude/hooks/lint-on-write.sh` runs after every edit |
| `make tools CMD='…'`, `make web-tools CMD='…'` | `tools`, `web-tools` | any other `composer`, `bin/console`, `npm`, `npx` command |
| CI jobs `licences`, `api`, `web`, `e2e` | the same | `setup-php`, `setup-node`, a postgres service container, `apt-get install logrotate` |

Still on the host, by design: `make`, `bash`, `git`, `docker`, and what the Makefile and the hook call on their own
(`id`, `stat`, `mkdir`, `rm`, `ip`, `sed`, `jq`). The `prod-image` CI job and `make up` already ran in Docker.

## Lessons

The lessons of one area are in its rules file, which loads when you read a file there: the API's in `.claude/rules/api.md`
(`api/**`), the web's in `web.md` (`web/**`), the images', deploying's and a shop's in `infra.md`, writing a gate or its
test in `gate-scripts.md` (`scripts/gates/**`, `scripts/tests/**`). Here: what applies before any file is read.

- Read a build's or download's exit code on its own (`cmd >log 2>&1; echo exit=$?`), never through `| tail`: the pipe's
  status is tail's, and three failed `docker build`s read as passes (2026-10-03). Playwright's browser download passes
  on Docker's default network and times out on the host's (dead IPv6 route), so `make playwright-browser` runs it there.
- Stage first, then run the checks, then commit: the SPDX gate enumerates `git ls-files`, and this clone has
  `core.fileMode=false`, so a new script also needs `git update-index --chmod=+x` (the executable-bits gate catches it).
  Commit a new script from the index (`git commit` with no pathspec): `git commit -- <paths>` re-reads the files and
  records them 100644 again, though the index said 100755 (2026-10-06).
- Use `git grep`, not `grep -rn`, for completeness sweeps; use `git --no-pager -c core.pager=cat diff --no-ext-diff`
  for programmatic diff reading (the external diff driver strips `+`/`-`). `docker compose config -q`, always `-q`.
- Back a file up before applying a mutant and restore from the backup; `git restore` reverts the uncommitted fix with it.
  Restore with a plain copy, never `cp -p` or `shutil.copy2`: the backup's older mtime makes Symfony keep the mutant's
  compiled container, so a mutated attribute (a listener priority) stays live in the next clean run (2026-09-15).
  Under `make up` the live api recompiles on every save, and a restore landing inside that compile is older than the
  container written from the mutant, which then stays: a sabotage removing `ResetInterface` left `StagedEffects` out of
  `services_resetter`, and every later response repeated another request's effects. After a sabotage of API source
  under `make up`, `docker compose restart api`, then `worker`, before any e2e (2026-10-21).
- A Bash `cd api` or `cd web` drifts the persistent cwd (every later relative path and cwd-keyed slug moves with it; the gate bypass is a recursive rule now, so it no longer re-arms the gates); use absolute paths
  or a subshell. Symfony's test client reboots the kernel between requests: re-find an entity after a request
  instead of `refresh()`. Angular's `whenStable()` covers pending HTTP, not the microtask after a flushed response.
- Local Playwright and Vitest timing is not evidence while other projects load this machine (load average 20+ on 8
  cores fails a different 5-second wait each run): check `uptime`, measure the server with curl, let CI arbitrate.
  A pipe hides a failing `make gate-api`: read the tool's own exit or its `[OK]` line, never the pipeline's status.
- Create source files with the Write tool, never `printf`/heredoc in Bash: the lint-on-write `php -l` hook sees only
  tool writes, and shell quote splicing turned two PHP string literals into bare words, so every functional test died at
  kernel boot (2026-09-15). Never run a sabotage batch in the same parallel block as a gate: it mutates what the gate reads.
- Know which stack is running before reading a measurement: `make up` is live (the dev server, the mounted source), while
  `make up-images` and CI serve a STATIC nginx build, where a `web/src/**` edit stays invisible until
  `docker compose up -d --build web`. Three rounds of measurement read as product defects before that was identified
  (2026-09-16). Under `make up`, a resource property change needs `make live-refresh`: API Platform's metadata pools
  outlive a worker restart.
- `make gate`'s licence half is every script `make gate-licences` runs (17 on 2026-09-19, CI's `licences` job), not
  `dependency-licences.php` alone: running that one and calling the job green put a red on master (2026-09-17). A web change
  needs it too: a new icon in a template is checked there (`icons-declared.sh`), and skipping it put a red on master again (2026-10-06).
- Pushing again CANCELS the previous commit's in-progress CI run, so a commit can read `e2e cancelled` and its new
  specs never ran anywhere but locally. Check the run of the commit that actually carries them (2026-09-20).
- Running e2e locally is worth the ten minutes (`make e2e`; `make playwright-browser` fetches Chromium and WebKit into
  `var/cache/ms-playwright` over Docker's default network, because the host network's IPv6 route to Google's storage is dead).
  Under `make up-images` both containers build from the working tree, so
  `docker compose up -d --build web api` first, or the browser tests the last image; `make up` serves the tree as it is. This caught two defects in one run that CI would have taken 28 minutes
  to report, one of them in the test itself (2026-09-21).
- `make gate-web` regenerates the API types, and the live `ng serve` of `make up` sees them missing for a moment and stops
  rebuilding: `docker compose restart web` after it, and wait for « Application bundle generation complete ». A grid narrowed
  to one column still grows an implicit second one for a `col-span-2` child: reset the children's `grid-column` too (2026-10-06).
- A file written after `make gate-web` ran is not gated: the plurals e2e went to CI unformatted and prettier stopped the web
  job before its lint and tests (2026-10-07). Write every file of a change, then stage, then run the gates.
- Never wrap `make tools` or `make web-tools` in `timeout`: it kills the `docker compose run` client and leaves the
  container running its suite. Three such orphans held over two cores (one for 36 hours) and timed every test out (2026-10-08).
  Run long suites in the background without `timeout`; after a timeout, `docker ps | grep tools-run` and stop the orphan.
- Restart `api` and `worker` one at a time under `make up`: both entrypoints run `cache:clear` on the shared `var/cache`, and
  together the api exited on the other's half-renamed `de_/` directory (2026-10-08). A gate's `composer install` clears that
  cache too, and the live web tier's start then fetched the OpenAPI document from a cold worker: 128M and 30 s ran out.
- `make test-api` from a worktree recreates the shared `twes-in-postgres-1` (`compose.yaml` names the project), cutting the live
  stack's database and leaving postgres bind-mounting the worktree's `init.sql`. In a worktree run `make tools CMD='cd api &&
  vendor/bin/phpunit …'` over a copy of `api/vendor`, and `docker compose up -d postgres` from the main tree before removing it (2026-10-13).
- `make gate-api` and `make gate-web` side by side (as ruled) can race on `api/var/cache`: the web gate exports the OpenAPI document
  through the api's console. « Cannot rename … de~ already exists », or `assets:install` KO in the web gate's
  export, is that race, not the change: rerun the red half alone (2026-10-14). Run `make gate`: it exports once and runs the halves
  in turn, which took as long as side by side here (about 15 min) and times nothing out; side by side, the suites starved (2026-10-15).
- Under `make up`, `migrate-watch` applies a migration written while the stack runs (it once failed sign-in twice in a day,
  on `role.version` and `demo_sandbox`, until `make up` ran). A migration edited after it was applied stays as first
  applied: read `docker compose logs migrate-watch` and `doctrine_migration_versions` before blaming the code (2026-10-17).
- `make up`'s api and worker run the `api-vendor` volume, which only the one-shot `migrate` service's `composer install`
  fills: after a Composer change the live api dies at boot until `make up` runs again (2026-10-16: « Rate limiter
  "mfa_verify" requires the Lock component », five hours unhealthy while CI, on fresh images, was green).
- A file list with newlines in `make check FILES="…"` splits the recipe: make ran each later file as a shell command (the
  tree's files are 775 on disk), and a TypeScript file's `import {` started ImageMagick's screen grab, which hung the run.
  Join the list on one line (`… | tr '\n' ' '`) and check it holds no newline before calling make (2026-10-18).
- `make web-tools CMD='…'` already starts in `web/`: a `cd web &&` before the command fails with exit 2, which a sabotage
  harness reading only the exit takes for a red suite. Run the unmutated command first and read the tally (2026-10-20).
- `make up` serves the tree live, so every saved edit rebuilds and reloads the page a person has open: while the developer
  shows the app, work in a worktree (`.claude/worktrees/<name>`), run only `make check`, unit suites and host gates there,
  and leave the stack alone. A count that reads a component's required inputs is read by the root `UnsavedChanges` before
  they are set (NG0950): gate it behind a signal set in `ngOnInit` (`PlaceMovement`, 2026-10-20).
- `make check` on a texts file (`web/public/i18n/**`) runs no spec that reads it: French punctuation (U+00A0 before « : »,
  U+202F before « ; ? ! ») and key parity live in `i18n-parity.spec.ts`, which only `make gate-web` runs. After any texts
  change, run the web gate before committing: r35's « depuis : » passed `make check` and reddened the gate (2026-10-22).
- `make up` and `make up-images` run `make seed`, which puts Demo and `codes@` back: on a platform the developer keeps
  empty for testing, rebuild without them, `make up-images SEED=0` (2026-10-27; a recipe parsed out of `make -n up`
  broke once the line computed the mounts digest).
- `make check` runs the specs of the files named, not the tests that read a whole directory or contract: a mail template
  is read by `MailDirectionTest`, a resource's shape by `OpenApiTest`, a French text by `i18n-parity.spec.ts`. Before
  gating, also run every test that `git grep -l`s the changed directory or resource name; drop 2 went red three times on
  them (2026-10-28), an hour a cycle under load 20 (the API suite took 37 to 52 minutes).
- `make gate` never boots the application: six cherry-picked fixes went green through it while the app stopped at start
  on NG0200 (an interceptor built `SpentBudget`, which asked for `TranslateService`, whose texts request runs the
  interceptors), every text a key and every e2e red. `app-config.spec.ts` now builds the real `appConfig` and asks that the
  texts request leaves; an interceptor reads what needs the texts lazily, through `Injector`. Before landing work built
  elsewhere, run one e2e on an image of the result (2026-11-01).
- A gate run in the main tree reads what git ignores: hunters' `web/e2e/*.local.spec.ts` reddened `e2e-settled-reads.sh`
  there while CI, on a clean clone, never sees them (2026-11-05). Gate through `make gate-snapshot`, whose worktree holds
  only the index; a red found only in the main tree is read against `git status --ignored` first.
- `flock` serves its waiters in no order: the main session's gate waited 76 minutes behind builder runs. While
  `/tmp/twes-in-gate.main-first` exists builders start no locked run; they run `make check` and the tests importing
  their files (at most 3 workers), and the full gate runs once per integrated batch. Before handing a builder a row,
  read `git log master..<branch>` of every builder: rows 199, 200 and 213 were built twice the same day.
