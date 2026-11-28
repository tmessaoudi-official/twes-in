# cloud-readiness Plan

The twes-in items of the cloud readiness audit of 2026-11-23 (a claude.ai session on twes-in master 61e55a22f, kit
bundle f21fd05): what a cloud session needs to work here as on the developer's computer. The kit's own items (its git
identity, docs, origin/HEAD, banner, shallow clones) and batch B (Symfony 8.2, on the developer's computer only) are
the developer's computer session's, in claude-code-setup.

## Decisions Log
- [2026-11-23 18:45] AGREED: npm install scripts denied, every one, with `strict-allow-scripts` (docs/SPEC.md § 7, same stamp).
- [2026-11-23 18:45] AGREED: the image scan's trivy is fixed now, with the Makefile's runs (docs/SPEC.md § 7, same stamp).
- [2026-11-23 18:45] AGREED: `.claude/cloud-setup.sh` starts bootstrap and the browser fetch detached (docs/SPEC.md § 7, same stamp).
- [2026-11-23 18:45] AGREED: the price-lists and subscription pages' texts move into parts (docs/SPEC.md § 7, same stamp).
- [2026-11-23 18:45] AGREED: per-item commits on the session branch, each after `make check` and the tests reading its area, then one `make gate` before the push: `make commit-gated` refuses any red snapshot, and a shallow clone reds `decision-stamps` on every one (accepted by the developer until the kit unshallows; `signed-commits` and `additive-migrations` pass vacuously here, CI on the whole history is the real check).

- [2026-11-23 18:58] AGREED: a cloud session is never silently worse than the computer; twes-in's part (cloud-doctor, the skip list, the shallow/tagless refusals) is built after the items above, the kit's goes to the PC prompt (docs/SPEC.md § 7, same stamp).
- [2026-11-23 18:58] ASSUMED (review): T4 passes `--strict-allow-scripts` on each install instead of a `web/.npmrc` — because `.gitignore` keeps every `.npmrc` out of the tree (a registry token may sit in one); a gate holds the flag on every install. Alternatives: force-add `web/.npmrc` with a `.gitignore` exception.
- [2026-11-27 21:18] AGREED: C1 + C1b (row 10): a cloud-only overlay compose.live-extra-ca.yaml (migrate-watch's build secret; the run-time CA for live api, migrate, web; web as the host user), appended by the Makefile only with the CA and the live file, idempotently; build-extra-ca.sh extended test-first; no 0644 copy; the gate stays a text check; verified on a fresh web-node-modules volume; committed on the cloud branch and kept local (the developer, AskUserQuestion, the recommended options)
- [2026-11-27 21:52] AGREED: the audit findings C2-C7 and S1-S5 recorded as rows 11-21 (S1 todo, P2/P3 deferred, kit ones named), and the branch sent to the remote for CI (the developer, AskUserQuestion, the recommended option)
- [2026-11-27 22:13] AGREED: row 22: Symfony 8.1.12 and Twig 3.31.1 inside the existing pins, for the 28 advisories composer audit found after master last went green (2026-11-23 to 25); the branch CI reds at make audit, so its e2e never ran; batch B (Symfony 8.2) on the computer will conflict on composer.lock (the developer, AskUserQuestion, the recommended option)
- [2026-11-27 23:11] ASSUMED (review): row 22 ran composer update with --with-all-dependencies, where the question named --with-dependencies, so doctrine/dbal 4.6.0 and monolog 3.13.0 (minors) and doctrine/collections, egulias/email-validator (patches) moved too — because every dependency is kept up to date always (2026-11-11 11:21). Alternatives: hold them back with --with-dependencies
- [2026-11-27 23:30] ASSUMED (review): corrects 23:11: doctrine/dbal back to 4.5.1 and monolog/monolog to 3.12.1, held with --with, since the AGREED rule of 2026-11-22 14:37 holds every x.y.0 until its .1 and S5.67 (b) names dbal 4.6 and monolog 3.13; the 23:11 basis (keep current) was wrong, and --with-dependencies would have moved them too (dry run); docs/UPDATE.md gains the rule and the hold. Alternatives: none, the rule decides

## Formal Plan

### T2 — the proxy CA reaches every toolchain run (class: a toolchain process downloading over TLS without the optional proxy CA)

The class search, `git grep -nE 'docker (run|build|buildx)\b' -- Makefile 'scripts/**/*.sh'`, each member:

| Where | What | Downloads? | Done |
|---|---|---|---|
| Makefile:70, 82, 293 | `docker build` of web-tools, ps-tools, rc-tools | yes | already `$(EXTRA_CA_SECRET)` |
| Makefile:87 | `docker run --network none` the installer's PowerShell tests | no network | exempt by its flag |
| Makefile:304 | `docker run` rc-lane (composer update) | yes | `$(EXTRA_CA_RUN)` |
| Makefile:326 | `docker run` playwright-browser | yes (proven red) | `$(EXTRA_CA_RUN)` |
| scripts/security/image-scan.sh:43 | `docker run` trivy (vulnerability DB, registry) | yes | optional mount + `SSL_CERT_FILE` |
| scripts/backup/restore-drill.sh:73 | `rm` in a backup volume | no | — |
| scripts/backup/stand-in-bucket.sh:13 | makes test certificates | no | — |
| scripts/onprem/onprem-check.sh:101, update-rehearsal.sh:94, staging/staging-check.sh:85 | a local registry, `-p 127.0.0.1` | no (pulls go through the daemon) | — |
| scripts/release/publish.sh:45 | reads a file in a local image | no | — |
| compose `tools`, `web-tools` (git, GnuTLS) | `fetch-xsd.sh` git fetches | yes (proven red) | `GIT_SSL_CAINFO` in compose.extra-ca.yaml |

The gate covers the Makefile (its runs are the toolchain's); a script's `docker run` is listed here, not gated, since
most reach nothing.

1. Red first: `build-extra-ca.test.sh` cases for a Makefile `docker run` without `$(EXTRA_CA_RUN)` (one line and a
   continuation, rc-lane's shape), `--network none` exempt, `GIT_SSL_CAINFO` required in the tools blocks; the
   fixture gains it. `image-scan.test.sh` (or its existing test) a case for the mount when `CCS_EXTRA_CA_FILE` is set
   and none when unset. Then the fixes, `.claude/rules/infra.md` Lesson.
2. Proof by execution: `var/cache/ms-playwright` moved aside, `make playwright-browser` red, fixed, green (exit 0);
   the three skipped API tests named before (`--display-skipped`), then `make test-api` with 0 skipped for want of an
   XSD; trivy's DB fetched in the container with and without the CA; `make -n` of both targets with the variable unset
   shows no mount (the computer and CI unchanged).

### T4 — npm install scripts denied, strict

Red first: with `--strict-allow-scripts` and no policy, a fresh `npm ci` fails naming the five. Then `allowScripts`
false for each (`npm deny-scripts`), the flag on every install (Makefile, web image, live container; not an `.npmrc`,
which `.gitignore` keeps out as a token's place), `scripts/gates/npm-install-scripts.sh` holding it, a fresh `npm ci`
clean, the web gate green; the web image is CI's prod-image (Docker Hub 429 here).

### T5 — two texts parts

`price-lists/{fr,en,ar}.json` and `licensing/{fr,en,ar}.json`, `textsPart` on their routes, the parity spec's
`parts`, what another screen reads kept in the main file (SPEC § 7 18:45). Before: `git grep -l "public/i18n" api scripts`
and every reader of a moved key. Proof: the budget gate without its WARN, `i18n-parity.spec.ts`, both pages' specs,
and their e2e (run on images here or read in CI's e2e jobs, said which).

### T6 — `.claude/cloud-setup.sh`

Detached (`setsid`, all three descriptors redirected), lock and `.done`/`.failed` markers, no-op off the cloud, exit
0 always; its test under `scripts/tests/` through the tmpdir and no-leftovers rules, listed in
`scripts/gate/licences.list`. CLAUDE.md's cloud paragraph says to wait for the marker. Proven only by a fresh session.

### Parity (2026-11-23 18:58): 194a371a the shallow refusals and the setup's unshallow, 72f9a67a the skip list, 6a3a4a17 cloud-doctor

### C1 + C1b (row 10) — a compose overlay or service the cloud composes wrong (class: an overlay naming a service its file set does not define; a service downloading as it starts without the proxy CA; a container writing the mounted tree under a fixed uid)

The class search (the audit of 2026-11-27): `git grep -nE 'npm (ci|install)|composer (install|update)|curl|wget|git (fetch|clone)' -- 'infra/**/*.sh' 'infra/**/entrypoint*' 'compose*.yaml'`, then the resolved live config (`docker compose -f compose.yaml -f compose.live.yaml config`) for each entrypoint, and `git grep -n '^USER' -- 'infra/**/*Dockerfile*'` for fixed users.

| Member | What | Done |
|---|---|---|
| `compose.extra-ca.yaml` `migrate-watch` | named in the overlay `make up-images` composes with `compose.yaml` alone, built from `./Dockerfile`: the stack never started | moved to `compose.live-extra-ca.yaml` |
| live `web`, `infra/web/live.sh` (`npm ci`) | no CA at run time; the CA file 0600 root; `USER 1000:1000` cannot write a root tree | CA mounted, `user:` the host's |
| live `migrate`, `infra/api/live-entrypoint.sh` (`composer install`) | no CA at run time (curl error 60, passed only with nothing to fetch) | CA mounted |
| live `api`, the same entrypoint | no CA at run time (passed, inferred because `migrate` filled the vendor volume first; not probed) | CA mounted |
| live `migrate-watch` (`migrate-watch.sh`, `migrate.sh`), `worker` | download nothing at start | — |
| `live.sh`'s `wget` of the api; the healthchecks' `curl`/`wget`; `infra/*/tests/` | the compose network or the container itself, no proxy | — |
| prod `USER twes` (`infra/api/Dockerfile`) | the prod stage, not composed by `make up` | not traced |

`build-extra-ca.sh` holds the four members marked done, by text (downloaders found from each service's mounted start script, `IMAGE_STARTS` for an image's own, a floor of three of them). The audit's other findings are rows 11–21 (S6 is not a defect: the modules catalogue and `me().modules` are two shapes kept in step by `switch()`); P2 and P3 ones are `deferred` (the 2026-11-10 23:42 ruling), the kit's go to claude-code-setup.

### T3, T7, K6

T3: `.claude/rules/expertise-core.md` refreshed against § 7's AGREED entries after 2026-11-02 00:39, up to this
plan's own; `expertise-verify.sh`; the REFERENCE file and the packs said as not refreshed if they are not.
T7: memories `where-to-resume-2026-11-23-php86-sf82` (CI read green, dependabot/5 open, batch B still on the computer)
and `cloud-toolchain-ca-at-run-time` (its run-time steps now done by the tree), MEMORY.md's lines; then
`ccs-memory-home.sh` with the developer's identity in `GIT_AUTHOR_*`/`GIT_COMMITTER_*`.
K6: CLAUDE.md's cloud paragraph says a cloud session starts in spec mode and how to switch.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | T2 the proxy CA in every toolchain run, the gate extended | M | done | afe54dcd | Makefile compose.extra-ca.yaml scripts/gates/** scripts/security/** .claude/rules/infra.md |
| 2 | T4 npm install scripts denied, strict | S | done | b9d8b800 | web/package.json infra/web/** Makefile scripts/gates/** |
| 3 | T5 price-lists and subscription texts parts | M | done | 90b99c90 | web/public/i18n/** web/src/app/** |
| 4 | T6 the cloud setup hook | S | done | 3ba2d50f | .claude/cloud-setup.sh scripts/tests/** scripts/gate/licences.list CLAUDE.md |
| 5 | T3 expertise refresh | S | done | 718b920e | .claude/rules/expertise-core.md |
| 6 | K6 the cloud paragraph's mode line | S | done | 718b920e | CLAUDE.md |
| 7 | Parity: shallow refusals and the setup's unshallow, the skip list, cloud-doctor | M | done | 6a3a4a17 | Makefile scripts/** api/tests/** .claude/cloud-setup.sh CLAUDE.md |
| 8 | Liveness of the setup's mark, a run leaving no log, one gate, push, CI read | S | done | 7860c7d5 | .claude/cloud-setup.sh scripts/cloud/** api/tests/** CLAUDE.md |
| 9 | KEEP_IMAGES=1 through every sub-make (Docker Hub 429), the advice reworded | S | done | - | Makefile scripts/tests/** scripts/cloud/** .claude/rules/infra.md |
| 10 | C1 + C1b of the 2026-11-27 audit: `make up-images` and `make up` start in a cloud session (the live overlay, run-time CA, live web as host user), gate extended | M | done | bbc83cce | compose.extra-ca.yaml compose.live-extra-ca.yaml Makefile scripts/gates/build-extra-ca.sh scripts/gates/tests/build-extra-ca.test.sh CLAUDE.md .claude/rules/infra.md |
| 11 | S1 (P1) a read's late answer is written after a sign-out or a company switch: `requestState`/`LatestRequest` moved by the forget, every `sessionSignal` holder guarded (unguarded: company, customer-account, price-lists, reports, notifications, pending-erasure facades), a gate holding it; test first | L | todo | - | web/src/app/** scripts/gates/** |
| 12 | S2 (P2) five facades keep company data in plain signals (company, party and article settings, document design, platform), exempted in `session-forgotten.sh`: convert them, drop the exemptions | M | deferred | - | web/src/app/** scripts/gates/session-forgotten.sh |
| 13 | S3 (P2) reference data read by several facades with no shared copy (custom fields, presentation/articles settings chains, groups and products): one holder each | M | deferred | - | web/src/app/** |
| 14 | S4 (P3) facades hand-rolling busy/error beside `requestState` (survey-grade: recount first) | S | deferred | - | web/src/app/** |
| 15 | S5 (P3) components injecting an API adapter directly (survey-grade; SPEC § 7's entry is ASSUMED): read it, ask for the ruling, then an ESLint `no-restricted-imports` | S | deferred | - | web/** |
| 16 | C2 (P2, kit) the project's PostToolUse lint hook never runs in a cloud session (it starts in /home/user; `${CLAUDE_PROJECT_DIR:-.}` reads /home/user) | S | deferred | - | .claude/settings.json .claude/hooks/** |
| 17 | C3 (P3, kit) `gh auth status` says the token is invalid while every `gh` call `/ci-watch` makes works | S | deferred | - | - |
| 18 | C4 (P3, kit) the kit's status hook and `.claude/cloud-setup.sh` both unshallow at start; one loses on `.git/shallow.lock` and logs fatal | S | deferred | - | .claude/cloud-setup.sh |
| 19 | C5 (P3) `rc-lane.yml` never runs on `claude/**` pushes: add the patterns or record why in SPEC § 7 | S | deferred | - | .github/workflows/rc-lane.yml |
| 20 | C6 (P3) the `tools` image has no `unzip`: Composer falls back to PHP's zip and warns of lost permissions | S | deferred | - | infra/api/Dockerfile |
| 21 | C7 (open) whether the environment cache keeps what the detached setup built: a session on a cached environment reads `docker images` before its bootstrap | S | todo | - | - |
| 22 | Symfony 8.1.12 and Twig 3.31.1 inside the pins: `composer audit` found 28 advisories (11 packages) published after master last went green, which reddened the branch's CI at `make audit` | S | done | 888c85803 | api/composer.json api/composer.lock THIRD-PARTY-NOTICES.md |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
- Whether the environment's cache keeps what a detached setup job built (T6): proven only by a fresh session.
### Fragile
### Known issues
- A shallow clone is refused by `decision-stamps`, `signed-commits` and `additive-migrations` (194a371a); the setup hook fetches the whole history, and `make cloud-doctor` says it.
