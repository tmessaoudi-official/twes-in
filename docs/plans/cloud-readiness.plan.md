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

### Parity (2026-11-23 18:58): c4724af6 the shallow refusals and the setup's unshallow, cb9aad39 the skip list, 60426207 cloud-doctor

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
| 1 | T2 the proxy CA in every toolchain run, the gate extended | M | done | bb49e0fe | Makefile compose.extra-ca.yaml scripts/gates/** scripts/security/** .claude/rules/infra.md |
| 2 | T4 npm install scripts denied, strict | S | done | fea912df | web/package.json infra/web/** Makefile scripts/gates/** |
| 3 | T5 price-lists and subscription texts parts | M | done | 6736d4c7 | web/public/i18n/** web/src/app/** |
| 4 | T6 the cloud setup hook | S | done | 82ce5719 | .claude/cloud-setup.sh scripts/tests/** scripts/gate/licences.list CLAUDE.md |
| 5 | T3 expertise refresh | S | done | 02da3d68 | .claude/rules/expertise-core.md |
| 6 | K6 the cloud paragraph's mode line | S | done | 02da3d68 | CLAUDE.md |
| 7 | Parity: shallow refusals and the setup's unshallow, the skip list, cloud-doctor | M | done | 60426207 | Makefile scripts/** api/tests/** .claude/cloud-setup.sh CLAUDE.md |
| 8 | Liveness of the setup's mark, a run leaving no log, one gate, push, CI read | S | done | 8298c260 | .claude/cloud-setup.sh scripts/cloud/** api/tests/** CLAUDE.md |
| 9 | KEEP_IMAGES=1 through every sub-make (Docker Hub 429), the advice reworded | S | done | - | Makefile scripts/tests/** scripts/cloud/** .claude/rules/infra.md |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
- Whether the environment's cache keeps what a detached setup job built (T6): proven only by a fresh session.
### Fragile
### Known issues
- A shallow clone is refused by `decision-stamps`, `signed-commits` and `additive-migrations` (c4724af6); the setup hook fetches the whole history, and `make cloud-doctor` says it.
