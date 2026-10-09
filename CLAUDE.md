# CLAUDE.md — twes-in

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
- **One `advisor()` call at goal start and one at goal end**, not per commit. One three-lens
  reviewer panel once, when the POC works, against a frozen commit; reviewers write to
  `var/claude/` and return one line; spawn them unnamed.
- **Say what was and was not certified by execution** in a goal's completion note, in one
  sentence each. "Tests pass" is not a claim about a container coming up.
- Craft lessons go under § "Lessons" below, five lines at most, only when they change what to
  do next time.
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

## Where things live

- `docs/SPEC.md` — product, architecture, goals, Decisions Log, status block.
- `docs/START.md` — bring-up, sign-in, creating users, the seed, `make reset`, the checks; `docs/UPDATE.md` — every
  version pin, its copies and how to bump it (`make versions` prints the values). Keep both true when changing what they describe.
- `LICENSING.md` — read before adding any dependency; `THIRD-PARTY-NOTICES.md` — generated by
  `scripts/notices/generate-third-party-notices.php`, never hand-edited; run it in the change that adds a dependency.
- `scripts/build-version.sh` — each part's build from git (`YYYY.MM.DD.N`, hash, « -dirty »), passed by the Makefile and CI
  to the images, shown in the footer (`docs/START.md` § 11); its test is `scripts/tests/build-version.test.sh`, run by `make gate-licences`.
- `scripts/gates/` — `dependency-licences.php` (five lists, each a maximum, `--dump-rules`; it also checks each
  `web/src/third-party/<name>/COMPONENTS.json`, what a shipped wasm compiles in, against the lock),
  `spdx-headers.sh`, `executable-bits.sh`, `icon-buttons-named.sh` (every Material icon button named through `appLabel`), `password-eyes.sh` (every password field has its eye, `shared/form/PasswordToggle` as the field's `matSuffix`, at least nine found), `outcomes-as-toasts.sh` (what was just done is a toast through `Feedback`; a `role="status"` line only for a page state it names), `compose-log-rotation.sh` (every compose service rotates its output, 10 MB × 5, through the `*logging` anchor), `planned-module-labels.sh` (every planned module in `PlannedModules` named, described and placed in the menu in fr and en, and every `{ module, label }` declaration of a planned action or settings card naming a planned module), `stored-items.sh` (every cookie the API sets and every `twes.*` storage key is declared in `web/src/app/shared/legal/stored-items.ts`, which the Cookies page renders, both ways; browser storage only inside a `*_STORAGE` token's factory; no script from another origin), `coming-gated.sh` (every template drawing the « Bientôt » chip sits beside a component that reads `showComing`, so « Montrer ce qui arrive » hides it), `tour-anchors.sh` (every name in `web/src/app/shared/tour/tour-anchors.ts` sits on an element as `data-tour`, and no `data-tour` is undeclared), `live-reload.sh` (every `*-page.ts` reading through its facade or API calls `reloadOn` or keeps a `LiveRecord`, or is listed with its reason), `version-pins.sh` (every copy of a version pin agrees, `docs/UPDATE.md`), `host-tools.sh` (CI names no `setup-php`, `setup-node` or bare host tool, and the Makefile runs one only inside an `in-*` target) and `design-tokens.sh` (no literal colour or static inline style in `web/src/app` outside `shared/theme`, and no colour class naming a role `web/src/tailwind.css` does not declare: Tailwind emits nothing for it), `icons-declared.sh` (every icon a template writes out is declared in `web/src/app/shared/icons/icons.ts`, which the icon font is cut to, and every declared one is still named), `float-casts.sh` (no `(float)` or `floatval` in `api/src`: money is a decimal string, and a cast that carries none says so on its line with `// float: <why>`), `decision-stamps.sh` (no Decisions Log stamp later than the commit `git blame` names for it, or than now while uncommitted; a late one found afterwards gains « (stamp corrected: written at HH:MM, commit <sha>) »; CI's licence job checks out the whole history for it), and `production-image.php`, run inside the production api image by CI's `prod-image` job; `tests/` beside them is each gate's own test, run by CI
  before the gate, each through `tests/no-leftovers.sh`, which reds a test leaving anything in its TMPDIR.
- `api/src/Shared/Infrastructure/Scheduler/Schedule.php` — the worker's one Symfony Scheduler schedule (`default`,
  consumed as `scheduler_default` beside `async`); a module adds a task with `#[AsPeriodicTask]` or `#[AsCronTask]` on its
  own service (the reminder run: `Module/Invoices/Infrastructure/Scheduler/`). Every task is idempotent, as one worker is
  assumed but not locked.
- Logs (docs/SPEC.md § 7, 2026-09-17): one file per channel and day under `api/var/log` in development; in production
  JSON through `fingers_crossed` into `Shared/Infrastructure/Logging/ChannelStreams`, on STDERR, or one file per
  channel under `LOG_DIRECTORY` when self-hosted, rotated by `infra/self-hosted/logrotate.conf` (its test rotates for
  real). A service picks its channel with `#[WithMonologChannel]`; list them with `bin/console debug:autowiring logger`.
- `docs/fiscal/<CC>.md` — sourced fiscal rules per country; `api/config/fiscal/<CC>.yaml` — the preset.
- `docs/spec/pricing-vectors.json` — the calculator's fixture set.
- `api/src/DataFixtures/` — the demo dataset (`make fixtures`, `docs/START.md` § 4), DoctrineFixturesBundle's own place,
  outside the contexts: `DemoCatalogue` is the data, `DemoCompanies` writes it through the use cases only, `Timeline`
  runs dated steps in order under a moved clock. A new workflow or state belongs in it; `DemoFixturesTest` loads it.
  `DataFixtures/Scale/` is the large-data generator (`make scale-data`, docs/START.md § 4): it clones the demo company's
  invoice graph in SQL, and `ScaleGenerator::unaccounted` fails when a new table reaching that graph is not classified.
- `api/src/<Context>/{Domain,Application,Infrastructure}/` — `Identity`, `Tenancy`, `Audit`, `Inbox` (the notification
  centre behind the `Notifications` port), `Fiscal`, `Settings` (the settings engine: declarations collected from every
  `DeclaresSettings` service, the three chains, `ReadSetting`), `ModuleRegistry` (the catalogue collected from every
  `DeclaresModule` service and the planned ones in `PlannedModules`, `module_state`, `module_interest` (« Me prévenir », told at start by `app:modules:announce-arrivals`, which `infra/api/docker-entrypoint.sh` runs after the migrations), the 404 guard for a switched-off module's resources and plain controllers),
  `CustomFields`, `Files` (the `file` table and the `FileStorage` port on Flysystem, on whichever filesystem
  `FILES_STORAGE` names: `local`, a volume under `FILES_DIRECTORY`, which `api/.env` ships, or `s3`, any
  S3-compatible bucket, which refuses to start without its own variables), `ImportExport` (a module declares an
  import with `DeclaresImport`; `RunImport` reads the file, the guide at `GET .../imports/{subject}` describes its
  columns, and every rejected row carries a `code` and `params` the screen translates, from a refusal's `reason`; a row
  imported may carry notes, `RowNotes`, such as the reference its new product was given; a subject offers switches,
  `ImportSwitch`, says what a row names for duplicates, `identityOf`, and what is said once per file, `finished`; a
  committed file is kept as an `ImportRun` under its SHA-256, so the same file is flagged, and the stock it moved
  carries its id: `Inventory/Application/ImportStock` is every file's quantities),
  `Watch` (« À surveiller »: a module declares its live conditions with `DeclaresWatch` in its own
  `Infrastructure/Watch/`, one statement per kind, gated by its module and permission; a core context's subject has no module and is always on, as `Tenancy`'s unsent invitations, marked by `InvitationMailFailed` when the worker gives up),
  `Legal` (the legal pages' versions, read by anyone at `GET /api/legal/{page}/{language}`; the shipped drafts are
  `api/resources/legal/<page>.<language>.md`, written at start where a page has none; the operator writes a new
  version and validates the latest through `/api/platform/legal-texts`, from `/platform/legal`),
  `FirstSteps` (« Premiers pas »: a context declares its step with `DeclaresFirstStep` in its own
  `Infrastructure/FirstSteps/`, done worked out from what is there, shown to whoever may do it),
  and the modules one level down in `api/src/Module/<Name>/`, the scanner among them (`Module/Scanning`: a phone lent
  to a computer tab as a scanner, `scan_pairing`, the tab's endpoints under its company and the phone's four public ones
  behind its key, named in `UnauthenticatedSweepTest`, which check the module themselves through `ScannerSwitch`) (a module reaches another only through a port it owns in its
  `Application/`, answered in the other's `Infrastructure/<Caller>/`, such as `Inventory/Application/ReceiptCosts`;
  `tests/Architecture/ModuleBoundariesTest` reds on another module's class that runs), `Shared` (docs/SPEC.md § 3
  "Architecture style"; `Shared/Domain/CompanyOwned` marks an entity the `Shared/Infrastructure/Doctrine/CompanyFilter`
  scopes to the company a request acts for, and `tests/Architecture/CompanyColumnTest` requires it; a paged list's provider
  uses `Shared/Infrastructure/ApiPlatform/Paging`, its repository `ListOrder` and the `SEARCH_TEXT` expression its trigram
  index is built on, and `SearchIndexesTest` names the pair). Domain: entities with Doctrine attributes, value objects (`Email`), repository interfaces.
  Application: use cases and ports (no framework import; `tests/Architecture/` enforces it). Infrastructure: Doctrine
  repositories, Symfony security (`SecurityUser` snapshot, `UserProvider`, handlers, listeners, `CsrfRequestListener`),
  API Platform resources (`Me`) and the OpenAPI decorator, the console command, the session handler. Every port has one
  adapter and Symfony aliases them (`services.yaml` scans `src/`); policy values are parameters there.
- `web/src/app/api/` — TypeScript types generated from the API's OpenAPI document (`make api-types`, gitignored;
  CI passes the document from the api job to the web job as an artifact; the web IMAGE generates them itself from
  the document the api image exports at build, through a compose `additional_contexts` service reference, so a
  clean clone builds without them and a stale local copy is kept out by `.dockerignore`). One directory per feature — over twenty, so
  check `ls web/src/app` rather than this sentence: `account` — « Mon compte », the person's own preferences, `auth`, `company`, `customers`, `delivery-notes`, `expenses`,
  `fiscal`, `hello`, `inventory`, `invitation`, `invoices`, `legal` — the public `/legal/<slug>` pages, `platform`, `products`, `settings`, `signup`,
  `vendors`, `pairing` — the phone's public `/pair` page, a scanner with no sign-in, `watch` — « À surveiller » and its home count, `first-steps` — « Premiers pas » on the home, `notifications` — the bell, the centre and the Centrifugo connection behind
  the `REALTIME_CONNECTOR` token, `shell` — the signed-in layout by window class (bottom bar below 600 px, rail to 1199, labelled from 1200), its nav manifest (`planned-nav.ts` places the planned modules the signed-in state's `plannedModules` lists; `COMING_NAV` keeps only the settings pages not built yet), the home manifest (`home-manifest.ts`: a module declares its `*_HOME` panel, loaded lazily, beside its `*_NAV`), the Ctrl K palette (`commands.ts`: a module declares its `*_COMMANDS` beside its `*_NAV`), account menu and the settings area behind the gear, whose list stays put beside the page and folds to a rail with `]`; every
  signed-in route is a child of it but `/customer-display`, a window facing the customer), `shared/` for what several features use and which imports no feature (ESLint enforces it; `session/`: the `Session`
  port the auth facade answers; `theme/`: runtime accent colour tokens (`accentTokens`: the accent as picked and its readable roles), the lifecycle map every module's status tones derive from (`lifecycle-tones.ts`),
  `ThemeFacade` (Automatique follows the device) and the scheme menu; `i18n/`: `LanguageFacade` and the language menu, and `PluralCompiler`, which says a count in its language's form (`{count, plural, one {…} other {…}}` in a screen text; never « (s) », the parity spec refuses it); `a11y/`: the
  `appLabel` directive, one string for a control's accessible name and its tooltip; `testing/`: providers specs share (`provideQuietFeedback` records toasts, `effectToasts` reads the kinds said; `announceSaved` plays a live change); `feedback/`: the `Feedback` port (toasts; `effect(key, params, kind)` after a consequential action), `RequestActivity` and its interceptor, the activity bar; `actions/`: `ScreenAction`, whose confirmation must name its `ActionKind` (annulable, corrigeable, définitif), and `kindAmong`, which the toast after an action reads, and `PlannedAction`, what a screen will offer once a planned module ships, listed « Bientôt » last in either bar's « ⋮ »; `legal/`: `LEGAL_PAGES`, `LegalFooter` (the centred copyright and legal-links line closing every page), `CookieNotice` (the last row of the page, sticky at its foot, standing on the phone bar through `--twes-bottom-bar-height`) and `STORED_ITEMS` (what is stored, which the Cookies page lists), `LegalLink` (a link that opens `LegalDialog` over the screen, a modified click left to the browser) and `LegalText` (a page's text, shared by the dialog and `/legal/<slug>`: the API's Markdown through `marked` and the sanitizing `[innerHTML]`, with its fr / en / ar switch), `LegalApi`; `tour/`: `TOUR_ANCHORS`, the `data-tour` places a guided tour may point at, `Tour` (a module declares its `*_TOURS` beside its `*_NAV`, collected in `shell/tours.ts` and offered in the palette as « Guide : … ») and `TourGuide`, which runs one on a CDK overlay beside the anchor on screen, never blocking the page (`web/e2e/tours.spec.ts` plays every guide the palette offers, so a tour that no longer runs is a red CI); `health/`: the API health client; `scan/`: `ScanWedge`, which tells a scanner's burst from typing (the shell opens `ProductScanCard` on one outside a field, only while the company has the `scanning` module on, `SCANNING_MODULE`), the `ScanBus` every scan goes through, `CameraView`, `PhonePairing` (the tab's side of a lent phone) and `ScanOffers` (what the card offers that phone); `icons/`: `ICONS`, every Material Symbol the app draws, which the icon font is cut to at build (`web/scripts/subset-icons.mjs`, the font gitignored under `src/generated/`), and `IconName`, the type an `icon` field takes; `qr/`: `QrCode`, a QR code drawn as SVG; `build/`: `BuildInfo` (the web build this page runs, read once, and the API's, read again and again), `BuildLine` (the footer's build line, the whole line copied on a click) and `NewVersionBanner` (« Recharger » once a newer bundle is served); `documents/`: `liveFigures`, what a document with lines comes to as it is typed, asked of the API's `…/preview` once typing rests, and `LineFiguresView`, a line's figures folded to its net and total; `ui/`: the bars, `WINDOW_CLASS`, `COARSE_POINTER` (a finger rather than a mouse, what a target is sized for), and a menu's foldable sections (`sectionFolds`, kept in `presentation.folded-sections`) and its edge fade and current entry in view (`NavScroller`); `customer-view/`: `CustomerView`, whether the API holds the sign-in on the customer screen (`Me.customerScreenCompanyId`), the `CustomerScreenHold` port the auth facade answers, and the interceptor that sends a tab the API refused as `customer_screen_locked` to the screen; `customer-display/`: `CustomerDisplay`, what a sale's scans show the customer display window (`/customer-display`, the `customer-display` feature directory) over a BroadcastChannel; `realtime/`: the one Centrifugo connection's connector, the `X-Tab` interceptor naming this tab, and `LiveChanges` (a page calls `reloadOn(kinds, reload, destroyRef)` to read its data again, quietly, when another tab or member changes those kinds); `settings/`: the `SettingsFacade` port, its API adapter
  `ApiSettings` (the presentation chain), the browser-storage adapter it keeps for signed-out pages, and the registry
  every presentation key must be declared in; `list/`: `ListDescriptor`, the pure view
  functions and `DataList` (given a `total`, it shows the page the API answered and emits `queryChange`); `form/`: `FormDescriptor`, `buildFormGroup` and `DescriptorForm`, and `DecimalInput` (`kind: 'decimal'`, or `appDecimal` on a hand-written input: the screen's decimal separator shown, a comma or a point taken, the API's point kept in the control); `liveRecord`, which merges another person's save into an open editor field by field over `mergeSavedVersion` and `RecordSync`, with the `RecordChanged` banner and `PartConflict` for what merges as one field, such as a document's lines), files named by role: `*-page.ts`, `*-facade.ts` (signals, what components inject), `*-api.ts`
  (the only importer of the generated types), `*-types.ts`, `auth-guard.ts`, `csrf-interceptor.ts`; translations in
  `public/i18n/{fr,en}.json` with a parity test.
- `api/translations/*.{fr,en}.yaml` — the only strings the API itself emits: the invitation mail (`emails`), fiscal
  labels and mentions (`fiscal`), and printed documents (`pdf`, laid out in `api/templates/pdf/`, whose `_document.css.twig` every document shares with the
  built-in layouts and the accent, and rendered by
  Gotenberg). `ApiTranslationParityTest` keeps each pair's keys identical. Everything a person reads in the SPA lives
  in `web/public/i18n/` instead, with its own parity test.
- `var/claude/**` — transient review output, gitignored.
- `.claude/settings.json` — `defaultMode: auto`, allow-list, empty `deny`, no `ask`; one
  `PostToolUse` hook (`.claude/hooks/lint-on-write.sh`) running `php -l` / `bash -n` on writes.
- `.claude/rules/expertise-core.md` (loads at session start), `.claude/EXPERTISE-REFERENCE.md` (read by section) and the four
  `domain-*` skills in `.claude/skills/` — what a generic engineer gets wrong here, the evidence surfaces and a trigger -> lesson
  table routing to the packs. Curated from the recorded decisions, memories and docs, dated (`Review date:`); do not hand-append:
  put a lesson under § "Lessons" and flag it for the next refresh. `bash ~/.claude/bin/expertise-verify.sh --dir .` checks them
  against the tree.
- `infra/api/Dockerfile` — FrankenPHP in worker mode in every target; two targets over one `base`: `dev`
  (`compose.yaml`, `make up`, CI's e2e) and `prod` (`compose.prod.yaml`, CI's `prod-image`: `php.ini-production`,
  `infra/api/conf.d/20-app.prod.ini` with preload, no dev packages, the cache warmed at build; `docs/START.md` § 10).
  PHP settings for every mode: `infra/api/conf.d/10-app.ini`.
- `Makefile` — `make up` (live: compose.yaml + `compose.live.yaml`, the source mounted, API workers restarted by
  FrankenPHP's watcher, the web tier on `ng serve`; `make up-images` runs the built images as CI does; `make live-refresh`
  after a resource property change; docs/START.md § 2), web :8090, api :8091, mailpit :8092, postgres :5433, gotenberg :8094, `lan` :8443 a phone's HTTPS door on this machine's network address, `infra/lan/Caddyfile`; the api image migrates at
  start, then `seed`: operator `operator@twes.local` / `twes-operator-dev`, authenticator secret
  `JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP`, `make operator-code` prints its current code; Playwright signs the operator in once, `web/e2e/session.ts`; `web/e2e/axe.ts` is the one WCAG scan, waiting for a fresh toast, and `web/e2e/toast.ts` the announced toast), `make gate` (licences + `composer gate`
  + `npm run gate`, each in its toolchain container, below; `npm run gate` starts by regenerating the types and `composer test` migrates the test database first),
  `make e2e` (Playwright against the running stack), `make fixtures` (the demo companies, appended after `seed`).
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
| `make gate-licences` | `tools` | the gate chain's `php` and `bash` lines, with `logrotate`, `jq`, `perl`, `python3`, `docker` |
| `make gate-stamps` | `tools` | `bash scripts/gates/decision-stamps.sh` alone; CI's `decision-stamps` workflow runs it on a push touching `docs/SPEC.md`, which `ci.yml` skips |
| `make gate-api`, `make test-api` | `tools` (+ compose `postgres`) | `cd api && composer gate`, `vendor/bin/phpunit` |
| `make gate-web`, `make test-web`, `make api-types` | `web-tools` | `cd web && npm run gate`, `npx ng test`, `npm run api:types` |
| `make api-openapi` | `tools` | `bin/console api:openapi:export` on host PHP |
| `make e2e`, `make gallery` (`E2E_ARGS=…`) | `web-tools` | `npx playwright test` and a host-installed Chromium |
| `make notices`, `make versions` | `tools` | `php scripts/notices/…`, `bash scripts/versions.sh` |
| `make php-lint FILE=…` | `tools` | `php -l`, what `.claude/hooks/lint-on-write.sh` runs after every edit |
| `make tools CMD='…'`, `make web-tools CMD='…'` | `tools`, `web-tools` | any other `composer`, `bin/console`, `npm`, `npx` command |
| CI jobs `licences`, `api`, `web`, `e2e` | the same | `setup-php`, `setup-node`, a postgres service container, `apt-get install logrotate` |

Still on the host, by design: `make`, `bash`, `git`, `docker`, and what the Makefile and the hook call on their own
(`id`, `stat`, `mkdir`, `rm`, `ip`, `sed`, `jq`). The `prod-image` CI job and `make up` already ran in Docker.

## Lessons

- Read a build's or download's exit code on its own (`cmd >log 2>&1; echo exit=$?`), never through `| tail`: the pipe's
  status is tail's, and three failed `docker build`s read as passes (2026-10-03). Playwright's browser download passes
  on Docker's default network and times out on the host's (dead IPv6 route), so `make playwright-browser` runs it there.
- The toolchain's `TMPDIR` is outside the working tree: `vendoredFonts` asks git which paths are ignored, and a fixture
  built under `var/` (ignored) is invisible to it, which read as four gate-test failures that passed on the host.
- A raw DBAL result is `mixed` under PHPStan max and a bare cast is refused: read it through a validating helper
  (`DataFixtures/Scale/Rows`) that throws on the unexpected. A sort or uniqueness check over numbers must partition by
  the series (company, establishment, document type), or another company's own numbers interleave (2026-09-29).
- `doctrine:fixtures:load` (`make fixtures`; or `make tools CMD='cd api && bin/console doctrine:fixtures:load --append'`, on the
  host's network) needs Mailpit (`docker compose up -d --wait mailpit`, SMTP on :8093), or it dies
  mid-load with a connection refused; Centrifugo being down only warns (2026-09-29).
- Stage first, then run the checks, then commit: the SPDX gate enumerates `git ls-files`, and this clone has
  `core.fileMode=false`, so a new script also needs `git update-index --chmod=+x` (the executable-bits gate catches it).
  Commit a new script from the index (`git commit` with no pathspec): `git commit -- <paths>` re-reads the files and
  records them 100644 again, though the index said 100755 (2026-10-06).
- Use `git grep`, not `grep -rn`, for completeness sweeps; use `git --no-pager -c core.pager=cat diff --no-ext-diff`
  for programmatic diff reading (the external diff driver strips `+`/`-`). `docker compose config -q`, always `-q`.
- Back a file up before applying a mutant and restore from the backup; `git restore` reverts the uncommitted fix with it.
  Restore with a plain copy, never `cp -p` or `shutil.copy2`: the backup's older mtime makes Symfony keep the mutant's
  compiled container, so a mutated attribute (a listener priority) stays live in the next clean run (2026-09-15).
- A Bash `cd api` or `cd web` drifts the persistent cwd (every later relative path and cwd-keyed slug moves with it; the gate bypass is a recursive rule now, so it no longer re-arms the gates); use absolute paths
  or a subshell. Symfony's test client reboots the kernel between requests: re-find an entity after a request
  instead of `refresh()`. Angular's `whenStable()` covers pending HTTP, not the microtask after a flushed response.
- Local Playwright and Vitest timing is not evidence while other projects load this machine (load average 20+ on 8
  cores fails a different 5-second wait each run): check `uptime`, measure the server with curl, let CI arbitrate.
  A pipe hides a failing `make gate-api`: read the tool's own exit or its `[OK]` line, never the pipeline's status.
- `make api-openapi` exports from the dev cache, so a stale `api/var/cache/dev` exports an OLD schema and the web gate
  fails locally while CI (fresh cache) passes, or the reverse. After an API resource change: `make tools CMD='cd api && bin/console cache:clear'`
  before `make gate-web` (2026-09-13: `Me` exported without `mfa`, three days after `MeMfa` landed).
- API Platform's metadata pools survive `cache:clear`: a property added to a resource is then silently dropped on write and
  missing from the OpenAPI export. After a resource property change run `make tools CMD='cd api && bin/console cache:pool:clear --all && bin/console cache:pool:clear --all --env=test'` (dev and test) (2026-09-14: `customFields` arrived empty until the pools were cleared).
- A `@var list<string>` on an API Platform property refuses nothing: a JSON object is denormalized with its keys and
  stored as a JSON object. A writable list property carries `#[Assert\Type('list')]` (2026-09-14: `choices` and
  `defaultTaxComponentIds` both accepted `{"first": …}` with 201).
- A template binding `[value]="fn(line)"` that builds a new object hands the child a new input on EVERY check, and a
  child effect over that input re-fires each time: `PickField` reset the text being typed on every keystroke. Compare
  a structured input by its content (`computed` with `equal`) before acting on it (2026-09-23).
- A `linkedSignal`'s `source` function is re-run, and its `computation` with it, whenever a signal it READS changes,
  even when the value it returns is the same: `() => \`${tab}|${product() != null}\`` reset an opened tab on every save.
  Give it a `computed` as its source, which only notifies when the value changes (2026-09-25, `ProductPage.tabKey`).
- A field added to a shared type, or a column added to a list, reaches specs and e2e you did not open: an API spec's
  `toEqual` on the whole mapped row, and an e2e reading a cell by position (`nth(3)`). Run the whole web unit suite
  before committing such a change, and read list cells by `[data-column="<id>"]`, never by index (2026-09-23: two reds).
- The API runs in FrankenPHP's worker mode: one kernel serves many requests. A service keeping anything a request set
  after construction implements `ResetInterface`, or it reaches the next person's request; `WorkerModeTest` runs requests
  on one kernel as the worker does (2026-09-27, row 167).
- Never name a PHPUnit helper `run()`, `count()` or `name()`: all are final on `TestCase` and the whole file fails to load.
- `\DomainException` extends `\LogicException`, so a test catching `\LogicException` also passes on every domain refusal
  (`InvalidInvoice` and the like): assert the refusal is not the domain one, or the guard under test can vanish unseen
  (2026-09-15: a credit note of another invoice looked refused while only its amount was).
- BrowserKit adds a same-origin `Referer` from its history to every request, and Symfony's CSRF manager accepts it
  as origin proof: a functional test of a cross-site request must set `Sec-Fetch-Site: cross-site` and a foreign
  Referer, and a "no origin at all" request needs `getHistory()->clear()` as well as empty server parameters.
- An array under `api_platform.defaults` (`output_formats`, `normalization_context`…) is MERGED into an operation's own
  value, never replaced by it: a default `output_formats: json` left a list declared JSON-LD answering plain JSON first.
  Restrict per operation, or through a metadata factory decorator (`JsonLdOnlyWhereNamed`, 2026-09-17).
- An entity's property default must be a literal, never another class's constant (`= Other::X`): the class then needs
  its defaults resolved at runtime, Doctrine's lazy ghosts skip that, and the local debug PHP aborts the whole PHPUnit
  run in `zend_lazy_object_init` (CI's release PHP does not assert, so only the local gate shows it). 2026-09-13.
- A `FormGroup` built in a `computed` over facade signals is rebuilt by any reload that returns equal data as new
  objects, discarding what was typed (2026-09-14: revising a product right after creating it saved the old price and
  said "saved"). Key the form on the record's id and the descriptor's content, and read its inputs `untracked`. A list
  read after the form opened does the same; a `linkedSignal` over what is edited rebuilds over the typed values.
- A PUT body that echoes a read row with its `id` answers 400 ("Cannot find object to populate"). An e2e cleanup that
  never checks its `fetch` status hides that, and leaks rows into the shared database: throw on `!response.ok`.
- Playwright's `page.request` does not send the session cookie (`Secure`, `SameSite=Strict`) to the local http stack, so
  it answers 401: fetch an API file from inside the page (`page.evaluate`) instead (2026-09-14, the delivery note PDF).
- Judge a rendered PDF by looking at it: `magick -trim -format '%@'` answered `+0+0` on pages whose content was visibly
  inset, which read as "Gotenberg ignores its margins". What was wrong was a template's `@page { margin: 0 }`, which
  overrides the renderer's margin fields in Chromium: every delivery note and invoice printed flush to the paper's edge
  (2026-09-14; `PdfTemplateMarginsTest` pins it).
- Create source files with the Write tool, never `printf`/heredoc in Bash: the lint-on-write `php -l` hook sees only
  tool writes, and shell quote splicing turned two PHP string literals into bare words, so every functional test died at
  kernel boot (2026-09-15). Never run a sabotage batch in the same parallel block as a gate: it mutates what the gate reads.
- Angular Material's `mat-card-content` overrides Tailwind layout utilities placed on it: put the flex or grid on a `div` inside
  it (2026-09-15: the platform page's switches ran together and its buttons wrapped under the company name).
- Know which stack is running before reading a measurement: `make up` is live (the dev server, the mounted source), while
  `make up-images` and CI serve a STATIC nginx build, where a `web/src/**` edit stays invisible until
  `docker compose up -d --build web`. Three rounds of measurement read as product defects before that was identified
  (2026-09-16). Under `make up`, a resource property change needs `make live-refresh`: API Platform's metadata pools
  outlive a worker restart.
- A dead operator session makes `/api/auth/me` answer 401 with no `company` key AT ALL, so
  `forgetPresentationChoices`'s `me.company === null` guard passes `undefined` straight into `me.company.id`:
  a spec dying with `Cannot read properties of undefined (reading 'id')` on its first line needs the session
  re-minted with `--project=setup`, not a teardown fix (2026-09-16).
- A mutated migration mutates its `down()` too: migrate the test database down before applying the mutant, and down with the
  mutant before restoring it, or what the mutant removed never leaves the schema (2026-09-15: a dropped CHECK stayed, and read green).
- `KernelTestCase::ensureKernelShutdown()` BOOTS the kernel to read its container for the cache directories, so a kernel a
  refusal left assigned but unbooted is booted again by the teardown — and refused there, turning a passing case into an
  error in a method that never asked for a kernel. Drop it instead (`static::$kernel = null; static::$booted = false;`)
  when testing anything that refuses to boot (2026-09-16, `DevelopmentKeysTest`).
- Axe reads colours mid-transition: after a runtime scheme change every element with a colour transition still shows a
  blend of the old scheme, and the contrast check fails on a moment, not a design. `ThemeFacade` holds transitions for
  one frame (`.theme-changing`); a new transition on colour must stay under that class (2026-09-16, row 28).
- Never animate `mat-sidenav`'s width inside an `autosize` container: autosize measures the drawer once per change,
  reads it mid-transition and leaves the page on a margin between the two widths (2026-09-16: x=200 between 248 and 80).
- A Doctrine inverse `OneToMany` is filled by a LOAD, so an in-memory repository never fills it and a unit test reads it
  empty. A domain rule that reads an entity's siblings — what a credit note's withholding leaves for the next one —
  needs the inverse side maintained in the entity beside the owning assignment, as the other collections here are
  (2026-09-16, `Invoice::creditNoteFor`). Adding the inverse side alone is a mapping change, not a migration: the
  owning column and its index already exist.
- An editor that keeps its form after saving must show what the API kept, not what was typed: a later comparison with
  the saved version otherwise reads `1300` against `1300.000` as a change and highlights a field nobody touched, or
  toasts a save that changed nothing. `LiveRecord.savedHere` writes the answer into the controls (2026-09-17).
- The shared Demo company outgrows a list page as e2e runs leave rows behind (52 customers on 2026-09-16, 25 a page): a
  scenario asserting its new row in a list filters the list first, or the row sorts onto page 2 and reads as missing.
- Every company screen failing in a local `make e2e` after `make fixtures` is the operator's several memberships, not a
  regression: a sign-in opens the pinned, else the last used, else the first company by name (2026-09-25), which may
  not be Demo. A scenario fixes it for itself by calling `inACompany(page, CSRF)` after `signIn` (it switches to
  Demo; a no-op in CI, which seeds Demo alone). Switching moves the operator's "last used" company too. Add it to any
  spec you need to run locally: "let CI arbitrate" hid a real failure for three commits (2026-09-20). And a test id
  renamed in a shared component is a blast-radius sweep over `web/e2e` too, not only over `web/src`. The same holds for a
  shown format (a day through the `day` pipe) and a suffix selector (`[data-testid$="-confirm"]`): `git grep` the old
  literal over `web/e2e`; both reddened e2e on master the same day (2026-10-06).
- Several unrelated e2e signing in and landing on `/two-factor` is Demo's `mfa_required` left `true` on this machine,
  not a regression: the seed never sets it, so CI is green on the same commit. Read it with
  `docker compose exec -T postgres psql -U twes -d twes -c "SELECT mfa_required FROM company WHERE name='Demo'"`.
- Playwright's `error-context.md` snapshots the default `page` fixture, so a failure inside a second
  `browser.newContext()` page shows another page entirely; read that page's last `screencast/page@<id>-*.jpeg` in the
  trace zip instead. Its frame is what showed a form whose inputs had collapsed to nothing (2026-09-17).
- A guard that slows an animation to make a moment reproducible must stay slow through the scan it guards: putting the
  speed back first, or letting an earlier scan eat the window, lets the fade finish by itself and the mutant that
  removes the fix passes. Twice green before the sabotage caught it (2026-09-17, `data-list.spec.ts` mid-fade).
- `make gate`'s licence half is every script `make gate-licences` runs (17 on 2026-09-19, CI's `licences` job), not
  `dependency-licences.php` alone: running that one and calling the job green put a red on master (2026-09-17). A web change
  needs it too: a new icon in a template is checked there (`icons-declared.sh`), and skipping it put a red on master again (2026-10-06).
- The images build from the WORKING TREE (`COPY api/ ./`), untracked files included: proving a bring-up from a dirty
  tree tests the work in progress, not HEAD. Prove it from `git worktree add <tmp> HEAD` (2026-09-19, `ImportExport/` WIP).
- php-cs-fixer rewrites a `/** @var */` above a `static $x = []` inside a method into `/* @var */`, and PHPStan then
  ignores it and reports `return.type` on what the method returns. Cache in a typed `private static array` property
  instead of a static variable (2026-09-20).
- `web/public/i18n/{fr,en}.json` keys are NOT alphabetical — that order is deliberate. Append a new section and assert
  the rest is unchanged by comparing the PARSED structures; sorting them produces a 2000-line diff of pure reordering
  that hides the 80 lines actually added (2026-09-20, same class as /stack's `jq … | unique` lesson).
- A gate that checks the leaves does not check the branch: `permission-labels.sh` passed green while the two biggest
  group HEADINGS above those labels were raw dotted keys. Ask what sits one level up from whatever a new gate
  enumerates (2026-09-20). Its floor is what then caught the follow-up — the gate read the `KnownPermissions` PORT,
  whose file exists and holds no groups, so three headings vanished silently; a discovery input that names a file must
  red when the file yields nothing, and the floor must sit ABOVE what the other half alone produces.
- The other half of that: a key BUILT by interpolation is invisible to the gate that greps for it. `VenuePlanSettings`
  first wrote `"settings.venue.shape.$shape.$side"` in a loop, so `setting-labels.sh` saw eleven keys where nineteen
  were declared and passed — its floor was below what the other declarations alone make (2026-09-21). Write a key a
  gate must find out in full, and set the floor so losing one file's worth reds.
- `GROUPS` is a bash special variable (the current user's group ids): assigning to it is silently ignored and it
  expands to a number. A test fixture whose JSON came out as `{1000,...}` was that, not a quoting bug (2026-09-20).
- `audit_log.at` is a `timestamp(0)`, so several writes inside one second tie: assert the SET of audit actions, never
  their order, or the case passes or fails by the second it happened to run in (2026-09-20).
- An audit entry is also the realtime signal — `DoctrineAuditTrail` stages each one as a live change whose kind is the
  entity type. A use case that records nothing leaves `reloadOn([...])` on its screen permanently dead, with no error
  anywhere (2026-09-20, `ManageRoles`).
- A mutant that does not TYPE-CHECK tests nothing, and reads exactly like a test that fails to notice: two sabotages
  showed no failures because the build had died, not because the guarantee was uncovered. Print the tally, not just
  the failures — an absent `Tests N passed` line is the tell (2026-09-20).
- A live change never comes back to the TAB that caused it (the `X-Tab` header), so a screen writing something the
  rest of the app reads must refresh it itself: `auth.refresh()`, or `SettingsFacade.refresh()` for the chain. Use
  `refresh()`, never `load()`, after a write — `load()` signs the person out when the API is briefly unreachable.
- Pushing again CANCELS the previous commit's in-progress CI run, so a commit can read `e2e cancelled` and its new
  specs never ran anywhere but locally. Check the run of the commit that actually carries them (2026-09-20).
- Assigning to a typed public array property (`$recorder->staged = []`) narrows PHPStan's view of it to `array{}`, and
  the next method call widens it back to the NATIVE `array` — the `@var list<X>` is gone, so every read after that is
  `mixed` and a `$x->id?->toRfc4122()` on it errors while the identical line in a test that never reset reads fine.
  Assert over what is there rather than clearing first (2026-09-21, `KeepStockTest`).
- A stock location that has seen a movement is KEPT (409), so an e2e that moves goods into a location it created
  cannot delete it and leaks one per run into the shared company. Say the dimension is uncertified instead.
- Running e2e locally is worth the ten minutes (`make e2e`; `make playwright-browser` fetches Chromium into
  `var/cache/ms-playwright` over Docker's default network, because the host network's IPv6 route to Google's storage is dead).
  Under `make up-images` both containers build from the working tree, so
  `docker compose up -d --build web api` first, or the browser tests the last image; `make up` serves the tree as it is. This caught two defects in one run that CI would have taken 28 minutes
  to report, one of them in the test itself (2026-09-21).
- A promoted `public readonly ?string $code` on an `\Exception` subclass is a FATAL redeclaration at class-load time
  (`\Exception::$code` is not readonly), reported nowhere near where it is written — and rtk condensed that fatal to
  `PHPUnit: ok`. Name it anything else, and read a suite's own tally through `rtk proxy` before believing a pass.
- A nested API Platform resource serializes as an IRI, not as an object: a property holding other resources needs
  `#[ApiProperty(readableLink: true)]` AND the nested resource's own read group in the operation's
  `normalizationContext`. Without both, a POST that answers what it created answers `/api/.well-known/genid/…`.
- Playwright's `evaluateAll` does NOT auto-wait: assert the count with `expect(locator).toHaveCount(n)` first, or a
  read straight after a reload returns `[]` and reads as "nothing was created".
- `tests/Architecture/ComposePortsTest` reads `compose.yaml`: run it after any compose edit, not only before. And a plain
  `docker compose up` rebuild drops what `make up` exports (`LAN_ORIGIN`): bring the stack back with `make up` (2026-09-23).
  `docker compose up <service>` also recreates what it depends on from `compose.yaml` alone, out of live mode: the api then
  served its image, and an e2e of an API change failed against the old code (2026-10-06).
- Global CSS beats Tailwind: `styles.scss` rules are unlayered, Tailwind's utilities sit in a layer, so any global or
  Material `display` wins over a template's `hidden`, `max-lg:hidden` or `flex`. `mat-sidenav-content` is `display:
  block` whatever its `flex flex-col` says, and `.twes-rail`'s `display: flex` showed a list `max-lg:hidden` should
  have hidden. Read the computed style before trusting a utility on a Material or `twes-` element (2026-09-26, row 151).
  And Material's component CSS loads AFTER `styles.scss`: a `.twes-*` class on a Material host loses to it at equal
  specificity. `.twes-activity-progress { position: fixed }` read `relative`, and the bar pushed the app 4 px down on
  every request for weeks; qualify with Material's own class (`.mat-mdc-progress-bar.twes-…`).
- An `aria-disabled="true"` control is disabled to Playwright AND to axe: `click()` waits forever for it to be enabled
  (activate it with `focus()` then `keyboard.press('Enter')`, which also proves the keyboard reaches it), and axe's
  `color-contrast` skips it and everything inside it, so no scan certifies its colours (2026-09-26, row 150).
- PHPStan's result cache sat in the SHARED `/tmp/phpstan` of the host and went stale across projects (before the toolchain
  moved to Docker: each `--rm` tools run now starts with its own empty `/tmp`, so this should not recur; re-check if a red
  appears in a file you did not edit): a run reported nine errors in delivery-note test files the change never touched, on the exact commit CI had just passed
  green. `vendor/bin/phpstan clear-result-cache` and the same file came back clean. Run PHPStan through `composer stan` (what `make gate-api` does), never bare — the script warms the test
  container XML first, without which the Symfony extension resolves service types as `mixed` (2026-09-22).
- `DateTimeImmutable::createFromFormat('!Y-m-d', '2026-13-45')` does not fail, it rolls into the next year: compare `format()` with the input before trusting a day a request sent. A functional test probing database constraints runs inside the suite's own transaction, where the first violation aborts the rest: open a nested `beginTransaction()` (a savepoint) per probe and `rollBack()` it (2026-10-02, price lists).
- `make gate-web` regenerates the API types, and the live `ng serve` of `make up` sees them missing for a moment and stops
  rebuilding: `docker compose restart web` after it, and wait for « Application bundle generation complete ». A grid narrowed
  to one column still grows an implicit second one for a `col-span-2` child: reset the children's `grid-column` too (2026-10-06).
- A template reference named like a component member (`#day` beside a `day` signal) shadows it inside the template and breaks the type check; a `matSuffix` inside an `@case`/`@switch` is not projected (NG8011), so give the field its own `@else if` branch. A swatch or icon-only button needs `appLabel` for the a11y e2e's tooltip rule AND text content for the template lint (2026-10-04).
- A Material dialog with `autoFocus: 'first-tabbable'` moves focus only once its open animation ends: an e2e that fills the
  first field straight away types into the page behind (the invoice payment flake). Wait for `toBeFocused()` on that field
  first. A matSuffix inside an `@if` beside its input is not projected either (NG8011): one mat-form-field per kind (2026-10-06).
- A `RequestEvent` listener that asks `Security::getUser()` on every API path makes the lazy firewall read a session for an
  anonymous request too, and Symfony then answers it `max-age=0, must-revalidate, private`: return first unless
  `$request->hasPreviousSession()`. Only the e2e of `/api/health`'s header saw it (2026-10-06, the customer-screen lock).
- A file written after `make gate-web` ran is not gated: the plurals e2e went to CI unformatted and prettier stopped the web
  job before its lint and tests (2026-10-07). Write every file of a change, then stage, then run the gates.
- When a search shape changes, sweep the API CALL as well as the type (`git grep '\.customers(\|\.products('`): `price-lists-facade` builds the search literal inline without naming its type, and twice only the build found it (2026-10-07).
- `docker compose restart api` keeps the image's own `docker-entrypoint`: an edit to `infra/api/docker-entrypoint.sh` runs only after
  `docker compose build api` and `make up` (2026-10-07: the new start step was missing from a September image).
- Never wrap `make tools` or `make web-tools` in `timeout`: it kills the `docker compose run` client and leaves the
  container running its suite. Three such orphans held over two cores (one for 36 hours) and timed every test out (2026-10-08).
  Run long suites in the background without `timeout`; after a timeout, `docker ps | grep tools-run` and stop the orphan.
- Restart `api` and `worker` one at a time under `make up`: both entrypoints run `cache:clear` on the shared `var/cache`, and
  together the api exited on the other's half-renamed `de_/` directory (2026-10-08). A gate's `composer install` clears that
  cache too, and the live web tier's start then fetched the OpenAPI document from a cold worker: 128M and 30 s ran out.
- Over the first page's 1 MB budget, measure before trimming: build with the budget lifted and `--stats-json`, then walk
  `main.ts`'s static imports in `dist/web/browser-stats.json` for the inputs. A shell-level service importing a feature's
  whole API class puts it on the first page (`ProductScanDetails` carried all of `InventoryApi`, 13.5 kB): load it with
  `await import()` where the shell needs one call of it (2026-10-08).
- Never remove `.cdk-overlay-container` in the middle of a spec: the CDK keeps the detached element and opens every later
  menu off the document, so a following `toBeNull()` passes on nothing (a mutant survived it, 2026-10-08). To open a second
  page in one case, `fixture.destroy()` the first, which closes its menu; leave the removal to `afterEach`.
- A test's `mktemp -d` with no `trap … EXIT` stays in the toolchain's TMPDIR, which lives on the host's tmpfs across runs: 35,098
  per-case git fixtures (about 22 inodes each) used all of `/tmp`'s inodes and froze every session on the machine (2026-10-09).
  Every gate test now runs through `scripts/gates/tests/no-leftovers.sh`; a new one starts with the `TMPDIR=$(mktemp -d)` line.
  A PHP test's `tempnam` is the same leak: `ApiTestCase::uploadFile` left one file per upload, 142 a suite run, until it removed it.
- A saved value whose field a form shows only sometimes (a preset's option) must be left out of the saved values when the
  field is absent: `dirtyCount` reads it as one unsaved change, the page holds every navigation away, and thirteen e2e that
  pass through Paramètres stayed on `/company/profile` (2026-10-09, `vatOnDebits`). The form's own spec builds the group
  and asks for zero changes on open.
