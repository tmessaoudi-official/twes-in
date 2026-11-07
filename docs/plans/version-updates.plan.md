# version-updates Plan

Order item 2 at the head of SPEC § 8: every package, image, tool and CI action to its latest stable release, one
ecosystem a commit (§ 7 2026-10-16 17:24). The TypeScript 7 hold is kept; a licence change stops the bump.
Procedure: docs/UPDATE.md, "After every bump" for each commit.

## Decisions Log
- [2026-10-16 18:44] AGREED: one ecosystem a commit; TypeScript 7 stays held; a licence change stops the bump (SPEC § 7 2026-10-16 17:24).
- [2026-10-16 19:01] ASSUMED (review): the framework-bundle recipe's AGENTS.md and CLAUDE.md stay out of api/, as G1a removed them (459a705c): the project's own CLAUDE.md and SPEC carry the process, and the recipe's generic conventions (AbstractController, ask before generating) contradict the code's. Upstream's only change since install drops the CLAUDE.md shim, so recipes:update brings neither back. Alternatives: a two-line AGENTS.md pointing at CLAUDE.md (advisor's suggestion), or the recipe's file verbatim.
- [2026-10-16 19:56] AGREED: the MCP packages' MIT → Apache-2.0 transition, dev-only under @angular/cli 22.2.3, is accepted (SPEC § 7, asked under the 17:24 ruling)
- [2026-10-16 21:15] ASSUMED (review): axe 4.14 turns on label-content-name-mismatch (WCAG 2.5.3, RGAA 6.1.5): the flagged controls are fixed, not the rule silenced. Key hints, avatar initials and capped counts (« 9+ ») are drawn by the stylesheet (data-glyph) as non-text content, the exact figure staying in the name per the count-badge ruling; the folded bell's Material small badge becomes a text-free dot; controls that show words and had a sentence as aria-label (floor, read-only and coming stock views, company rail, line figures) are named by their words with the sentence as tooltip/description or a leading sr-only phrase; the layer show/lock toggles become switches; the member row is named with its role, the language button with its code. Basis: ACT 2ee8b8 (symbolic text is non-text, aria-hidden text still counts) and the advisor's read. Alternatives: the shortcut and initials written into each name; disabling the rule (refused: RGAA).
- [2026-11-05 11:38] AGREED: round 2 (2026-11-05): steps 7-14 as planned: the torn-read fix first, then PHP, npm, Trivy, image patches, image minors one commit each, make versions made complete; TypeScript 7 still held
- [2026-11-05 11:38] AGREED: pushes: the CI fix alone now, then each ecosystem committed after its own local gate and the bumps pushed once as one batch
- [2026-11-05 11:38] AGREED: runs-on ubuntu-latest, docker-ce in a shop's WSL and Docker Desktop through winget stay unpinned on purpose, written in docs/UPDATE.md « Not pinned here on purpose »: a shop gets Docker's security fixes without a release from us
- [2026-11-05 14:29] AGREED (ratified ASSUMED, developer 2026-11-05): pgBackRest 2.60.0 keeps its new default, a restore that stops on an invalid page checksum and an expire that refuses (rule: never silent; data checksums are on, show data_checksums = on); docs/RUNBOOK.md's two restores say to try an older --set, then --checksum-page-error=n with app:data:check and verify-chain. Alternatives: set checksum-page-error=n in pgbackrest.conf (warns only), or hold 2.59.3.
- [2026-11-05 15:14] AGREED (ratified ASSUMED, developer 2026-11-05): Mailpit 1.33.0, Gatus 5.38.0 and SeaweedFS 4.52 go in one commit rather than one each: none runs where a shop or a server serves (development, the monitor, the drill's stand-in bucket), each is proven by its own check (an e2e reading mail, make monitor-check, make backup-drill), and one gate instead of three saves about 30 minutes. Alternative: one commit and gate each, as step 12 first said.

## Formal Plan
Inventory on 2026-10-16 (`composer outdated --direct`, `npm outdated`, Packagist): Symfony 8.1.9 is the newest
release, so PHP moves by patches and two minors (PHPStan 2.3, phpstan-symfony 2.1); npm by patches and minors
(Angular 22.2.3, Playwright 1.64.1, angular-eslint 22.6, material-symbols 0.48, zxing-wasm 3.1.5); TypeScript 7.0
is out and held.

1. PHP packages: `composer update` of the direct packages, `make notices`, the gates.
2. npm packages but zxing-wasm: Angular through `ng update`, then the rest; Playwright with its ARG in
   `infra/web/tools.Dockerfile`; material-symbols re-cuts the font; `make notices`, the gates, the e2e.
3. zxing-wasm 3.1.5: the tarball read first (its zxing-cpp commit and what it compiles in), then the manifest.
   Read 2026-10-16 19:15: 3.1.5 (MIT, published 2026-10-06) ships the same file list as 3.1.4 and compiles zxing-cpp
   2ecec3f5be0ee803f6e14a5a2c7028c0cfe525b4 (from 0b2d9a8f…), wasm sha256
   aecc1876de036c62c8419f67a5e1a16b1698a325bcd190aa84810d516e263931. The 41 commits between add no file outside test/;
   the code they touch is zxing-cpp's own (librscpp included: Apache-2.0, its headers say so), and the wasm carries the
   same component markers. The manifest's components stay; its version, integrity and audit line move.
4. Docker images: each tag in `make versions` against its registry, every copy moved (`version-pins.sh`).
   Registries read 2026-10-16 19:05: mailpit v1.31.4 → v1.31.6, caddy 2.11.6 → 2.11.7 (app, deploy and monitor),
   gotenberg 8.37.0 → 8.38.1, frankenphp 1.12.7 → 1.13.0 (php8.5-trixie), docker 29.8.2 → 29.9.0 (cli),
   node 26.10.0 → 26.12.0 (alpine), nginx 1.31.6 → 1.31.7 (alpine), supercronic v0.2.49 → v0.2.50 (sha256 re-taken
   against its release notes' sha1). Current: centrifugo v6.9.7, postgres 18.6-trixie, seaweedfs 4.48, composer 2.10.3,
   gatus v5.37.0, glitchtip 6.2.6; pgBackRest and restic are trixie packages, moved only with Debian.
5. GitHub Actions: each `uses:` against its releases.
   Read 2026-10-16 20:31: `actions/checkout@v7` (latest v7.0.1) and `actions/upload-artifact@v7` (latest v7.0.2), the
   only two, are on their latest major, which their tags follow: nothing moves. Pinning by commit is the tool audit's to weigh.

### Round 2 (2026-11-05)

Inventory read 2026-11-05 11:00-11:35 (`composer outdated`, `npm outdated`, Docker Hub tags, `gh api` releases,
`apt-cache policy` in `postgres:18.6-trixie`, symfony.com/releases.json, nodejs.org/dist/index.json). Already newest:
Symfony 8.1.11 (8.2 maintained, not stable), postgres 18.6, registry 3.1.2, glitchtip 6.2.6, PSScriptAnalyzer 1.25.0,
restic 0.18.0-1+b4 and curl 8.14.1-2+deb13u7 (the Debian candidates), zxing-wasm, the three actions (SHA pins stand).
Held: TypeScript 7.0.2 (ruled), Node 27 (alpha; 26 is the LTS « Lithium »).

7. CI red on 98fc86b46 (`backup heartbeat: 28 passed, 1 failed`, « the schedule says it is alive while a step runs »,
   its marker read empty): a torn read. `echo … > due` truncates before it writes; a tight-loop reader saw 75 empty reads
   of 80,945 with the keeper writing every millisecond. The class: the ten writes of `twes-backup` into
   `$OUTCOME_DIRECTORY` (lines 111, 141 ×2, 143, 175, 176, 186, 200, 206, 230), read by `twes tick` (`due`, each step's
   line), `since` (`<step>.ok`) and the keeper (`due.step`). One helper writes a temporary file beside each and renames
   it. Search: `grep -nE '>>? *"?\$OUTCOME_DIRECTORY' infra/postgres/backup/twes-backup`.
8. PHP: the 13 direct packages composer outdated lists (api-platform 5.0.5 → `cache:pool:clear --all`).
9. npm: Angular 22.2.4/22.2.6 through `ng update`, ngx-translate and tailwind in pairs, eslint, typescript-eslint,
   vitest, tslib, marked, harfbuzzjs, postcss, material-symbols 0.48.3, three and @types/three 0.187 (imports only core
   classes and OrbitControls, none removed in r187).
10. Trivy 0.67.2 → 0.76.1 by index digest: 0.67.2 is inside GHSA-mcj4-mphf-j9ff (HIGH, < 0.71.1), GHSA-8rc5-4fr6-64pw
    and GHSA-q3fv-x8vg-qqm4; the March compromise touched 0.69.4-0.69.6 only.
11. Image patches: frankenphp 1.13.5 (published 2026-11-05, past the 1.13.4 first read), docker-cli 29.9.3 (the host daemon's), node 26.13.0, nginx 1.31.8, caddy 2.11.8,
    centrifugo 6.10.2, the .NET SDK 10.0-noble digest, supercronic 0.2.51.
12. Image minors, one commit each: gotenberg 8.39.0, pgBackRest 2.60.0, composer 2.11.1 (its one BC break,
    `source-fallback`, unused here), mailpit 1.33.0, gatus 5.38.0, seaweedfs 4.52.
13. `make versions` prints every pin: every `ARG *_VERSION` of every Dockerfile (curl, PSScriptAnalyzer and Playwright
    were missing), every action of every workflow with its tag (only `ci.yml`'s were read), no empty `PHP (CI)` row;
    docs/UPDATE.md's Actions row says SHA, and the three unpinned items are written as deliberate.
14. Research: which of the 18 `echo > "$state/…"` writes of `scripts/onprem/twes` another process reads while written.
    Read 2026-11-05: no fix. Every state file read for its content is read under the lock its writer holds
    (`update-in-progress` at 735-738 and `last-update` under `update.lock`), by `status`, which only shows it, or by
    `onprem-check.sh` and `update-rehearsal.sh` after the run; a tick never overlaps itself (systemd, and Windows'
    `-MultipleInstances IgnoreNew`). The worst torn read skips an interrupted update's resume for one tick (line 733's
    `-s`), which the next tick takes. Search: `grep -noE '> *"\$state/[a-z.-]+"' scripts/onprem/twes`; 26 files, the
    content readers traced, the rest not (Inferred).

### Round 3 (2026-11-07): the coming releases

Ruled in `docs/plans/stable-release.plan.md`'s Decisions Log (2026-11-07 16:14, 17:37): a readiness map of every pin, a
probe of what already allows PHP 8.6 and Symfony 8.2, a non-blocking CI lane on the release candidates, the switch once a
release is out and green on its first patch release.

| Pin | Ours | Next | When (source) | Kind |
|---|---|---|---|---|
| Symfony | 8.1.11 | 8.2 (`v8.2.0-BETA1` tagged) | Nov 2026; **8.1 unsupported from Jan 2027**, 8.2 until Jul 2027, then 9.0 and 8.4 LTS (symfony.com/releases) | minor, required |
| PHP | 8.5 (FrankenPHP 1.13.5) | 8.6.0 (RC5 on php.net, `php:8.6-rc` RC4 on Docker Hub) | GA planned 19 Nov 2026 (wiki.php.net/todo/php86) | minor |
| FrankenPHP | 1.13.5-php8.5 | a php8.6 image | none yet: 0 `php8.6` tags on Docker Hub (2026-11-07) | gate for PHP 8.6 |
| PostgreSQL | 18.6 | 19 (`postgres:19rc1`, 2026-11-06) | GA after the RCs | **major: data upgrade on every shop** |
| Node | 26.13 | 27 (alpha) | 26 LTS since 2026-10-28, supported to 2029-04-30 (nodejs/Release) | nothing to do |
| Angular | 22.2.4 | 22.3 (`next` 22.3.0-next.4); no 23 published | npm dist-tags | watch |
| TypeScript | 6.0.3 | 7.0.2 latest | held back by ruling (EXPERTISE §2) | held |
| minimatch (dev-only) | 10.2.6 | 11.0.1 | Dependabot #5, moderate DoS, never shipped | patch when dependents allow |

The probe (`var/claude/release-probe/`, worktree `.claude/worktrees/probe86`, image `twes-in-tools-php86`: PHP 8.6.0RC4,
the same eight extensions as FrankenPHP's, Symfony 8.2.0-BETA1):
- Composer resolves both: PHP 8.6 with no package refusing it; Symfony 8.2 moves the 42 `symfony/*` packages and drops
  `symfony/polyfill-uuid`, no third-party bundle in the way.
- PHP 8.6 alone (Symfony 8.1): one blocker, `PostgresSessionHandler` lacks `create_sid()` (PHP 8.6 warns « will be
  required in PHP 9.0 », and the dev error handler stops the container's build on it); with it, PHPStan clean and the 98
  tests below green. php-cs-fixer 3.95.28 refuses to run on 8.6 (« supports PHP syntax only up to PHP 8.5 »).
- Symfony 8.2 (on PHP 8.6): the full suite 5,184 tests, 9 failures, all from `MailerAssertionsTrait` now counting the
  mails of every kernel a test booted (`TestContainer::getKeptState`), where 8.1 counted the last request's; not in
  framework-bundle's CHANGELOG at BETA1. PHPStan: 8 errors, one spot (`tests/Functional/RouteSweep.php:67-68`, a
  `string|non-decimal-int-string`), reported per class using it. Deprecations: 3, all ours (below).
- Not proven: the worker runtime on PHP 8.6 (no FrankenPHP image), the web half (no Node or Angular change involved),
  the e2e, PostgreSQL 19.

## Completion note
- Certified by CI only, never run locally: the full e2e (`stock-map.spec.ts:429` included), the production images, the
  deploy check and the staging check, all green on 3d0767f9 (six workflows of six).
- Certified locally: `make gate` (API 3,256 tests, web 3,495, licences, budgets), the six Label in Name mutants killed,
  the before and after captures identical to the pixel.
- Step 5 confirmed currency only: `actions/checkout@v7` and `actions/upload-artifact@v7` are the majors of the latest
  releases (v7.0.1, v7.0.2, `gh release list` 2026-10-16); no commit was needed. Pinning by tag rather than by commit is
  the tool audit's finding D-2.
- Round 2 (steps 7-14), pushed 9cbac74fc..9921cb725: CI green on 9921cb725, five workflows of five, twelve jobs (api,
  web, licences, prod-image, the three e2e shards, publish, the deploy, staging, restore-drill and monitor checks).
  Certified locally besides: each step's `make gate-snapshot`, `make backup-drill` on pgBackRest 2.60.0 and SeaweedFS
  4.52, `make monitor-check` on Gatus 5.38.0, the stock-map e2e on the patched images, a credit note's PDF looked at on
  Gotenberg 8.39.0. Not certified by execution: the go-jose verdict in `.trivyignore` (Inferred from where Caddy uses it),
  pgBackRest's damaged-page and refused-expire paths (its release notes only), the local api image scanned being the
  development target rather than the release's.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | PHP packages | M | done | dca1b5e27 | api/composer.json api/composer.lock THIRD-PARTY-NOTICES.md |
| 2 | npm packages (zxing-wasm apart) | M | done | 25adca69a | web/package.json web/package-lock.json infra/web/tools.Dockerfile THIRD-PARTY-NOTICES.md |
| 3 | zxing-wasm | S | done | 454f5d108 | web/package.json web/package-lock.json web/src/third-party/** |
| 4 | Docker images | M | done | 9f62df7c9 | compose*.yaml infra/** |
| 6 | axe 4.14's Label in Name on master | S | done | efe7c9783 | web/src/** web/e2e/** web/public/i18n/** |
| 5 | GitHub Actions | S | done | - | .github/workflows/** |
| 7 | Outcome files written whole (CI red) | S | done | 9cbac74fc | infra/postgres/backup/twes-backup scripts/tests/backup-heartbeat.test.sh |
| 8 | PHP packages, round 2 | M | done | 388c23770 | api/composer.json api/composer.lock THIRD-PARTY-NOTICES.md |
| 9 | npm packages, round 2 | M | done | 14231bb16 | web/package.json web/package-lock.json THIRD-PARTY-NOTICES.md |
| 10 | Trivy 0.76.1 | S | done | 9c64cd80d | scripts/security/image-scan.sh scripts/tests/image-scan.test.sh |
| 11 | Image patches | M | done | 46ace95a0 | compose*.yaml infra/** |
| 12 | Image minors | M | done | 177e5d6a2 | compose.yaml infra/** scripts/backup/** |
| 13 | make versions complete | S | done | 9921cb725 | scripts/versions.sh docs/UPDATE.md |
| 14 | twes state writes: torn reads | S | done | - | scripts/onprem/twes |
| 15 | PHP 8.6 blocker now: `PostgresSessionHandler::create_sid()` delegating to the wrapped handler (Symfony's has it on 8.1) | S | todo | - | api/src/Identity/Infrastructure/Session/PostgresSessionHandler.php |
| 16 | Symfony 8.2 deprecation now: `SymfonyDomainEvents` takes the Contracts `EventDispatcherInterface` | S | todo | - | api/src/Shared/Infrastructure/Events/SymfonyDomainEvents.php |
| 17 | RC lane: a non-blocking CI job on PHP 8.6 RC + Symfony 8.2 beta/RC (the probe's image into infra/, its deprecations listed per run) | M | todo | - | .github/workflows/** infra/** |
| 18 | Symfony 8.2 switch, at 8.2.1, before 8.1's January 2027 end: a mail-count helper marking before the request (the 9 tests), RouteSweep's type, `security.route_loader`, the csrf_protection recipe, the ping middleware (doctrine-bundle 3.3.4 still wires the deprecated class) | M | todo | - | api/composer.* api/config/** api/tests/** |
| 19 | PHP 8.6 switch at 8.6.1: waits on a FrankenPHP php8.6 image and php-cs-fixer support for 8.6 | M | blocked | - | infra/api/Dockerfile api/composer.json |
| 20 | PostgreSQL 19 at 19.1: dump and restore on every shop, `pgbackrest stanza-upgrade`, rehearsed by `make onprem-check` and `make update-rehearsal` | L | todo | - | compose*.yaml infra/postgres/** scripts/onprem/** |
| 21 | Class « a release learnt of late »: a script reading each pin's upstream calendar (php.net, symfony.com/releases, Docker Hub tags, npm dist-tags, Node's schedule) beside `make versions` | M | todo | - | scripts/versions.sh scripts/** |
| 22 | minimatch 11.0.1 (dev-only, Dependabot #5) once its dependents allow | S | todo | - | web/package.json web/package-lock.json |
<!-- /progress-block -->
### Blocked
- 19: no FrankenPHP image on PHP 8.6 (Docker Hub, 2026-11-07); php-cs-fixer refuses 8.6.
### Needs input
- npm 11.20 warns that five packages run install scripts not covered by `allowScripts` (@parcel/watcher, esbuild, lmdb,
  msgpackr-extract, protobufjs; they still run, 2026-11-05). Approving them is a supply-chain choice, for the developer,
  before an npm release makes the list required.
### Needs research
### Fragile
### Known issues
