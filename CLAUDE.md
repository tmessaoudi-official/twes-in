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
  most five bullets. No phase markers, no evidence tables. Ask only for a genuinely ambiguous
  request, a user-visible product decision, or anything that would weaken an invariant below,
  always through `AskUserQuestion` with the recommended option first and a visible
  "none of these" escape.
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

## Licensing invariants — the cardinal rule

1. **Upstream code never enters this tree.** Invoice Ninja's backend and web UI are Elastic
   License 2.0; a translation of their code is a derivative work. Build from the contract, the
   behaviour and the standards (EN 16931, UBL, CII, Factur-X, Peppol, El Fatoora), never from
   the source. Reference clones live in `/tmp/xxx/**`, never in the working tree.
2. **Never reproduce, disable or reimplement a licence-key or branding gate** from upstream.
   Our own plan gating is ours and is fine.
3. **Every dependency is permissive and recorded** in `THIRD-PARTY-NOTICES.md` (generated from
   the lock files, checked by `scripts/gates/dependency-licences.php` in CI). Read the actual
   LICENSE file of anything whose lock entry is not a bare SPDX identifier: PrimeNG's whole family
   was refused at G0 because "SEE LICENSE IN LICENSE.md" turned out to be an eligibility-gated
   commercial licence with a bundled licence-key verifier. Permitted for
   anything distributed: **MIT, Apache-2.0, BSD-2-Clause, BSD-3-Clause, ISC, 0BSD, MIT-0,
   CC0-1.0, BlueOak-1.0.0**. Narrow dev-only exceptions: CC-BY-4.0 / CC-BY-3.0 build-time data;
   MPL-2.0 dev-only tooling (Angular's `lightningcss`); Python-2.0 dev-only tooling (`argparse` under
   `@hey-api/openapi-ts`, ruled 2026-09-09); OFL-1.1 vendored fonts. "AGPL-compatible"
   is the wrong test: a copyleft dependency kills the commercial branch. Adding an identifier is
   a licensing decision, never a build fix.
4. **Copyright stays wholly owned**: no outside contribution without a CLA (none exists yet).
   New source files carry `SPDX-License-Identifier: AGPL-3.0-or-later`: php-cs-fixer adds it to PHP,
   `scripts/gates/spdx-headers.sh` checks PHP, TypeScript and shell.
5. **All branding is ours and configurable**; nothing hardcoded.
6. **When a licensing question is unclear, STOP and ask.** One-way door.

## Git

- Autonomous `git add`, `commit` and `push` are authorised for CI-green, self-contained work
  on **`master`**, the only branch. Plain `git push`, never `-u`. No `--force`, no history
  rewrite, no other branch, no pull request.
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
- `scripts/gates/` — `dependency-licences.php` (four lists, each a maximum, `--dump-rules`),
  `spdx-headers.sh`, `executable-bits.sh`, `icon-buttons-named.sh` (every Material icon button named through `appLabel`), `outcomes-as-toasts.sh` (what was just done is a toast through `Feedback`; a `role="status"` line only for a page state it names), `compose-log-rotation.sh` (every compose service rotates its output, 10 MB × 5, through the `*logging` anchor) and `version-pins.sh` (every copy of a version pin agrees, `docs/UPDATE.md`); `tests/` beside them is each gate's own test, run by CI
  before the gate.
- Logs (docs/SPEC.md § 7, 2026-09-17): one file per channel and day under `api/var/log` in development; in production
  JSON through `fingers_crossed` into `Shared/Infrastructure/Logging/ChannelStreams`, on STDERR, or one file per
  channel under `LOG_DIRECTORY` when self-hosted, rotated by `infra/self-hosted/logrotate.conf` (its test rotates for
  real). A service picks its channel with `#[WithMonologChannel]`; list them with `bin/console debug:autowiring logger`.
- `docs/fiscal/<CC>.md` — sourced fiscal rules per country; `api/config/fiscal/<CC>.yaml` — the preset.
- `docs/spec/pricing-vectors.json` — the calculator's fixture set.
- `api/src/<Context>/{Domain,Application,Infrastructure}/` — `Identity`, `Tenancy`, `Audit`, `Inbox` (the notification
  centre behind the `Notifications` port), `Fiscal`, `Settings` (the settings engine: declarations collected from every
  `DeclaresSettings` service, the three chains, `ReadSetting`), `ModuleRegistry` (the catalogue collected from every
  `DeclaresModule` service, `module_state`, the 404 guard for a switched-off module's resources and plain controllers),
  `CustomFields`, `Files` (the `file` table and the `FileStorage` port on Flysystem, on whichever filesystem
  `FILES_STORAGE` names: `local`, a volume under `FILES_DIRECTORY`, which `api/.env` ships, or `s3`, any
  S3-compatible bucket, which refuses to start without its own variables),
  and the modules one level down in `api/src/Module/<Name>/`, `Shared` (docs/SPEC.md § 3
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
  clean clone builds without them and a stale local copy is kept out by `.dockerignore`). One directory per feature — twenty of them, so
  check `ls web/src/app` rather than this sentence: `auth`, `company`, `customers`, `delivery-notes`, `expenses`,
  `fiscal`, `hello`, `inventory`, `invitation`, `invoices`, `platform`, `products`, `settings`, `signup`,
  `vendors`, `notifications` — the bell, the centre and the Centrifugo connection behind
  the `REALTIME_CONNECTOR` token, `shell` — the signed-in layout by window class (bottom bar below 600 px, rail to 1199, labelled from 1200), its nav manifest, the home manifest (`home-manifest.ts`: a module declares its `*_HOME` panel, loaded lazily, beside its `*_NAV`), the Ctrl K palette (`commands.ts`: a module declares its `*_COMMANDS` beside its `*_NAV`), account menu and the settings area behind the gear; every
  signed-in route is a child of it), `shared/` for what several features use and which imports no feature (ESLint enforces it; `session/`: the `Session`
  port the auth facade answers; `theme/`: runtime accent colour tokens,
  `ThemeFacade` (Automatique follows the device) and the scheme menu; `i18n/`: `LanguageFacade` and the language menu; `a11y/`: the
  `appLabel` directive, one string for a control's accessible name and its tooltip; `testing/`: providers specs share (`provideQuietFeedback` records toasts; `announceSaved` plays a live change); `feedback/`: the `Feedback` port (toasts), `RequestActivity` and its interceptor, the activity bar; `health/`: the API health client; `realtime/`: the one Centrifugo connection's connector, the `X-Tab` interceptor naming this tab, and `LiveChanges` (a page calls `reloadOn(kinds, reload, destroyRef)` to read its data again, quietly, when another tab or member changes those kinds); `settings/`: the `SettingsFacade` port, its API adapter
  `ApiSettings` (the presentation chain), the browser-storage adapter it keeps for signed-out pages, and the registry
  every presentation key must be declared in; `list/`: `ListDescriptor`, the pure view
  functions and `DataList` (given a `total`, it shows the page the API answered and emits `queryChange`); `form/`: `FormDescriptor`, `buildFormGroup` and `DescriptorForm`; `liveRecord`, which merges another person's save into an open editor field by field over `mergeSavedVersion` and `RecordSync`, with the `RecordChanged` banner and `PartConflict` for what merges as one field, such as a document's lines), files named by role: `*-page.ts`, `*-facade.ts` (signals, what components inject), `*-api.ts`
  (the only importer of the generated types), `*-types.ts`, `auth-guard.ts`, `csrf-interceptor.ts`; translations in
  `public/i18n/{fr,en}.json` with a parity test.
- `api/translations/*.{fr,en}.yaml` — the only strings the API itself emits: the invitation mail (`emails`), fiscal
  labels and mentions (`fiscal`), and printed documents (`pdf`, laid out in `api/templates/pdf/` and rendered by
  Gotenberg). `ApiTranslationParityTest` keeps each pair's keys identical. Everything a person reads in the SPA lives
  in `web/public/i18n/` instead, with its own parity test.
- `var/claude/**` — transient review output, gitignored.
- `.claude/settings.json` — `defaultMode: auto`, allow-list, empty `deny`, no `ask`; one
  `PostToolUse` hook (`.claude/hooks/lint-on-write.sh`) running `php -l` / `bash -n` on writes.
- `Makefile` — `make up` (compose, web :8090, api :8091, mailpit :8092, postgres :5433, gotenberg :8094; the api image migrates at
  start, then `seed`: operator `operator@twes.local` / `twes-operator-dev`, authenticator secret
  `JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP`, `make operator-code` prints its current code; Playwright signs the operator in once, `web/e2e/session.ts`; `web/e2e/axe.ts` is the one WCAG scan, waiting for a fresh toast, and `web/e2e/toast.ts` the announced toast), `make gate` (licences + `composer gate`
  + `npm run gate`, which starts by regenerating the types; `composer test` migrates the test database first),
  `make e2e` (Playwright against the running stack).
  These are exactly CI's jobs; run the gate chain AFTER `git add -A`, because the SPDX gate and
  `git ls-files` see staged files and a cached-only enumeration misses a brand-new one.
- Node 26 for the web tier (`web/.nvmrc`). On this machine it is nvm's
  `/stack/tools/nvm/versions/node/v26.*/bin` (v26.8.2 on 2026-09-13; /stack's env-update bumps the patch), which a
  fresh shell does not have on PATH.
- PHP 8.5 for the api tier, as in CI. The host's first `php` on PATH is phpbrew's `php-master` (8.6-dev), which
  php-cs-fixer refuses to run on: prepend `/stack/tools/phpbrew/php/php-8.5.10/bin` before `composer gate`.

## Lessons

- Stage first, then run the checks, then commit: the SPDX gate enumerates `git ls-files`, and this clone has
  `core.fileMode=false`, so a new script also needs `git update-index --chmod=+x` (the executable-bits gate catches it).
- Use `git grep`, not `grep -rn`, for completeness sweeps; use `git --no-pager -c core.pager=cat diff --no-ext-diff`
  for programmatic diff reading (the external diff driver strips `+`/`-`). `docker compose config -q`, always `-q`.
- Back a file up before applying a mutant and restore from the backup; `git restore` reverts the uncommitted fix with it.
  Restore with a plain copy, never `cp -p` or `shutil.copy2`: the backup's older mtime makes Symfony keep the mutant's
  compiled container, so a mutated attribute (a listener priority) stays live in the next clean run (2026-09-15).
- A Bash `cd api` or `cd web` drifts the persistent cwd and re-arms every project-scoped gate hook; use absolute paths
  or a subshell. Symfony's test client reboots the kernel between requests: re-find an entity after a request
  instead of `refresh()`. Angular's `whenStable()` covers pending HTTP, not the microtask after a flushed response.
- Local Playwright and Vitest timing is not evidence while other projects load this machine (load average 20+ on 8
  cores fails a different 5-second wait each run): check `uptime`, measure the server with curl, let CI arbitrate.
  A pipe hides a failing `composer gate`: read the tool's own exit or its `[OK]` line, never the pipeline's status.
- `make api-openapi` exports from the dev cache, so a stale `api/var/cache/dev` exports an OLD schema and the web gate
  fails locally while CI (fresh cache) passes, or the reverse. After an API resource change: `bin/console cache:clear`
  before `make gate-web` (2026-09-13: `Me` exported without `mfa`, three days after `MeMfa` landed).
- API Platform's metadata pools survive `cache:clear`: a property added to a resource is then silently dropped on write and
  missing from the OpenAPI export. After a resource property change run `bin/console cache:pool:clear --all` in dev and
  with `--env=test` (2026-09-14: `customFields` arrived empty until the pools were cleared).
- A `@var list<string>` on an API Platform property refuses nothing: a JSON object is denormalized with its keys and
  stored as a JSON object. A writable list property carries `#[Assert\Type('list')]` (2026-09-14: `choices` and
  `defaultTaxComponentIds` both accepted `{"first": …}` with 201).
- Never name a PHPUnit helper `run()`: `TestCase::run()` is final and the whole file fails to load.
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
- The web container serves a STATIC nginx build, never a dev server (`infra/web/Dockerfile` builds and copies
  `dist/web/browser` into nginx), so a `web/src/**` edit is invisible to the browser and to Playwright until
  `docker compose up -d --build web`. Three rounds of measurement read as product defects before that was
  identified (2026-09-16).
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
- Several unrelated e2e signing in and landing on `/two-factor` is Demo's `mfa_required` left `true` on this machine,
  not a regression: the seed never sets it, so CI is green on the same commit. Read it with
  `docker compose exec -T postgres psql -U twes -d twes -c "SELECT mfa_required FROM company WHERE name='Demo'"`.
- Playwright's `error-context.md` snapshots the default `page` fixture, so a failure inside a second
  `browser.newContext()` page shows another page entirely; read that page's last `screencast/page@<id>-*.jpeg` in the
  trace zip instead. Its frame is what showed a form whose inputs had collapsed to nothing (2026-09-17).
- A guard that slows an animation to make a moment reproducible must stay slow through the scan it guards: putting the
  speed back first, or letting an earlier scan eat the window, lets the fade finish by itself and the mutant that
  removes the fix passes. Twice green before the sabotage caught it (2026-09-17, `data-list.spec.ts` mid-fade).
- `make gate`'s licence half is ELEVEN scripts (`.github/workflows/ci.yml`'s `licences` job), not
  `dependency-licences.php` alone: running that one and calling the job green put a red on master (2026-09-17).
- The images build from the WORKING TREE (`COPY api/ ./`), untracked files included: proving a bring-up from a dirty
  tree tests the work in progress, not HEAD. Prove it from `git worktree add <tmp> HEAD` (2026-09-19, `ImportExport/` WIP).
