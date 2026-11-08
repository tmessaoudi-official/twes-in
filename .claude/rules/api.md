---
paths:
  - "api/**"
---
# API — twes-in

What a session needs while working in `api/`, beside CLAUDE.md, which keeps the process, the licensing
invariants, git, the commands and the lessons that apply before any file is read. A new lesson of this area goes at
the end of § Lessons here.

## Where things live

- `scripts/fiscal/fetch-xsd.sh` — the published schemas the fiscal XML tests read (TEJ, Factur-X EN 16931, the CEN's CII),
  fetched anew into the toolchain's temporary directory and pinned (`xsd-sources.tsv`: a git folder's tree id, the TEJ zip's
  SHA-256), never kept here; `make gate-api`, `gate-api-tests` and `test-api` run the suite through it (`WITH_FISCAL_XSD`).
  A source that does not answer is « not run » (a warning on GitHub) and its tests skip; a changed schema stops the run.
- `api/src/Shared/Infrastructure/Scheduler/Schedule.php` — the worker's one Symfony Scheduler schedule (`default`,
  consumed as `scheduler_default` beside `async`); a module adds a task with `#[AsPeriodicTask]` or `#[AsCronTask]` on its
  own service (the reminder run: `Module/Invoices/Infrastructure/Scheduler/`). Every task is idempotent, as one worker is
  assumed but not locked. An issued document's PDF that could not be stored at issue is stored by `StoreMissingInvoicePdfsEveryQuarterHour` and `StoreMissingDeliveryNotePdfsEveryQuarterHour`, at most `app.documents.pdf_catch_up_batch` a company per pass.
- Logs (docs/SPEC.md § 7, 2026-09-17): one file per channel and day under `api/var/log` in development; in production
  JSON through `fingers_crossed` into `Shared/Infrastructure/Logging/ChannelStreams`, on STDERR, or one file per
  channel under `LOG_DIRECTORY` when self-hosted, rotated by `infra/self-hosted/logrotate.conf` (its test rotates for
  real). A service picks its channel with `#[WithMonologChannel]`; list them with `bin/console debug:autowiring logger`.
- `api/src/Demo/Infrastructure/Dataset/` — the demo dataset, production code (the demo sandbox writes it too):
  `DemoCatalogue` is the data, `DemoDataset` writes one company through the use cases only, under a moved clock
  (`Timeline` runs dated steps in order), for whoever the caller makes its owner. A new workflow or state belongs in it;
  `DemoFixturesTest` loads it. `api/src/DataFixtures/` is DoctrineFixturesBundle's own place, development only, outside
  the contexts (`LayerDependenciesTest` reds on a context naming it): `DemoCompanies` gives the catalogue to the seeded
  operator with one tester per role (`make fixtures`, `docs/START.md` § 4).
  `DataFixtures/Scale/` is the large-data generator (`make scale-data`, docs/START.md § 4): it clones the demo company's
  invoice graph in SQL, and `ScaleGenerator::unaccounted` fails when a new table reaching that graph is not classified.
- `api/src/<Context>/{Domain,Application,Infrastructure}/` — `Identity` (a change to how an account signs in, thirteen of them (a lock set by wrong guesses among them, and an operator's
  deactivation, reactivation or ending of its sessions, which say what follows rather than ask whether it was you), is mailed to it through `Application/Security/SecurityNotices`, queued in the change's transaction, never switched off), `Tenancy`, `Audit` (the one trail: a class recording an action declares it and where it is recorded, a company's, an account's or either, with `DeclaresAuditActions`, collected into `AuditVocabulary`; the trail refuses an undeclared or misplaced action under kernel.debug, `UndeclaredAuditAction`, and warns in production; `AuditActionWordsTest` holds what a journal shows to the web's `ACTIVITY_ACTIONS` and its words in the `activity` part, both ways; `ListenedKindsStagedTest` holds every kind a screen listens to, through `reloadOn`, `liveRecord`, `listRecord` or `LiveChanges.on`, to one the API stages, a heard audit action's kind, a `LiveChange` named by a literal or a constant, or the erasure catalogue's, so no screen waits on a kind nothing says), `Inbox` (the notification
  centre behind the `Notifications` port; a `NotificationKind` names its payload's amounts, quantities, days and moments,
  which a mail writes through `Shared/Infrastructure/Mail/MailFigures` in its reader's formats, `Settings/Application/
  ReaderFormats`, and the bell through its `notificationRecord`; `NotificationFieldsTest` reds on a word's field left unnamed), `Fiscal`, `Settings` (the settings engine: declarations collected from every
  `DeclaresSettings` service, the three chains, `ReadSetting`), `ModuleRegistry` (the catalogue collected from every
  `DeclaresModule` service and the planned ones in `PlannedModules`, `module_state`, `module_interest` (« Me prévenir », told at start by `app:modules:announce-arrivals`, which `infra/api/docker-entrypoint.sh` runs after the migrations), the 404 guard for a switched-off module's resources and plain controllers),
  `CustomFields`, `Files` (the `file` table and the `FileStorage` port on Flysystem, on whichever filesystem
  `FILES_STORAGE` names: `local`, a volume under `FILES_DIRECTORY`, which `api/.env` ships, or `s3`, any
  S3-compatible bucket, which refuses to start without its own variables), `ImportExport` (a module declares an
  import with `DeclaresImport`; `RunImport` reads the file, the guide at `GET .../imports/{subject}` describes its
  columns, and every rejected row carries a `code` and `params` the screen translates, from a refusal's `reason`; a row
  imported may carry notes, `RowNotes`, such as the reference its new product was given; a doubtful cell (an identifier's
  shape or check, an e-mail, a phone, an IBAN, a BIC, a barcode) is set aside by `SetAside` rather than refusing its row: the
  record is written without it and marked « À vérifier » by `Infrastructure/Import/SetAsideMarks` (the `imports` text
  domain), and the report notes `set_aside` on that cell (a phone kept outside its country's ranges under « Garder et marquer » is noted `phone_kept_to_check`, judged by the use case's own `phoneKept` before the write); a subject offers switches,
  `ImportSwitch`, worded by the screen alone (`ImportColumnsTest` holds every label and note to each language; the
  products file's: `default_taxes`, the line defaults for a new product naming no tax, and `unpriced_inactive`, a new
  product with no price created switched off at 0; its `cost_price_gross` column is a cost with taxes, kept less the
  VAT the company gets back), says what a row names for duplicates, `identityOf`, and what is said once per file, `finished`; a
  committed file is kept as an `ImportRun` under its SHA-256, so the same file is flagged, and the stock it moved
  carries its id: `Inventory/Application/ImportStock` is every file's quantities; a file in the shop's own headings
  is read through `pairing`, a JSON object of heading to column (`''` sets a column aside), which the committed run
  keeps and the guide offers again as `lastPairing`; « Annuler cet import », `Application/Undo/UndoImport` at `POST …/import-runs/{id}/undo`, takes back within
  `app.records.undoable_for` and behind a step-up what the run created that nothing uses, as a `RecordRemoval` of kind
  `import` the bin puts back through `RestoreUndoneImport`, the stock it moved through `ImportedStock` (reversals at their
  own cost) and the rows a module wrote through `UndoesImportedRows`; « Imports récents » is `GET …/import-runs`),
  `Watch` (« À surveiller »: a module declares its live conditions with `DeclaresWatch` in its own
  `Infrastructure/Watch/`, one statement per kind, gated by its module and permission; a core context's subject has no module and is always on, as `Tenancy`'s unsent invitations, marked by `InvitationMailFailed` when the worker gives up, and its contacts to check, `ContactsToCheckWatch` (the profile's and each establishment's, as `StoredContacts::toCheck` judges them, records with no mark of their own); a part counting from the clock rather than the company's day also says when it next changes with nothing written, `ComesDueWithTime`, and the summary's `readAgainIn`, seconds from now, carries the earliest, or the next day, which the web's `WatchFacade` reads again at; `WatchKindsTest` holds every part whose constructor takes the clock to it),
  `Texts` (« Textes »: any text the screens, the mails and the printed documents show, worded otherwise for the installation by
  an operator, then for a company by its owner (`company.texts`), which wins there; `ShippedTexts` is the release's catalogue
  the images carry, the `TextsInForce` port (`OverridingTranslator`, the translator itself) what a mail or a PDF writes, the fiscal texts locked; a changed original is flagged; a wording is stored under the file that shipped its text, and
  `FollowMovedTexts`, `app:texts:follow-moved` at every start, moves it after a text moved into a page's part),
  `Legal` (the legal pages' versions, read by anyone at `GET /api/legal/{page}/{language}`; the shipped drafts are
  `api/resources/legal/<page>.<language>.md`, written at start where a page has none; the operator writes a new
  version and validates the latest through `/api/platform/legal-texts`, from `/platform/legal`),
  `Tenancy` also holds « Accès du support » (the owner's `SupportGrant`, with what is wrong if they say, which only the operator
  reading it is given, on /platform and in the banner, which also says it when the owner closes it mid-visit; `Application/Support/SupportAccess`, which `CompanyGuard`
  and `PermissionVoter` ask for an operator's read-only way in; `SupportReadOnlyListener`, which refuses anything but a GET
  and every file a controller marked `BulkFile` answers under a grant (`BulkFilesTest`: every action recording
  `export.downloaded` carries the mark), and records each page read),
  `FirstSteps` (« Premiers pas »: a context declares its step with `DeclaresFirstStep` in its own
  `Infrastructure/FirstSteps/`, done worked out from what is there, shown to whoever may do it),
  `DataHealth` (the data checks: a context declares what no constraint states with `DeclaresDataChecks` in its own
  `Infrastructure/DataHealth/`; `RunDataChecks` runs them all, nightly through the worker and as `app:data:check`,
  and tells the operators each check, company and count, never a document's contents),
  `Installation` (what a shop's installation script, `scripts/onprem/twes`, tells through `app:installation:tell`: a release
  out to each company's owners, a failed or rolled-back update, a failing health check or backup to the operators; a line
  it refuses exits 2, which the script drops),
  `Records` (deleting what nothing names, and « Récemment supprimés »: a context declares what names its records with
  `DeclaresRecordReferences` in its own `Infrastructure/Records/`, which `RecordReferenceCoverage` holds to every column
  that names one; `RemoveRecords` refuses a record in use, takes the rows a reference says belong to it (`owned`: a
  product's codes, photos, places, settings; a row owned through such a row, a line's tax, is declared `below` it and goes
  before it), keeps them a day in `record_removal_row` for `RestoreRecords`, and the
  worker makes them final every quarter hour, letting go the files nothing names any more through the Files context's
  `UnnamedFiles`, which the erasure shares; `Merge/MergeRecords` merges records of a subject that says how, its
  `MergedAway`: an alias stays switched off pointing at the record kept (`merged_into_id`, which `RecordReferenceCoverage`
  requires of its table), a removed one goes as a delete does; a subject that says nothing is not merged; a subject may be only some of its table's rows (`whereColumn`: a document's drafts, an issued one answering
  404) and named through another table (`labelTable`: a draft by its customer); `PreviewMerge`
  says what moves and what stays, and the undo is the removal's restore; what no row carries a module does through
  `MergeEffects`, asking `MergeActor` for a right the merge needs, such as the stock a product held, which
  `Inventory/Application/Merge/CarryMergedStock` carries to the product kept as `merge` movements and back on an undo,
  after which the stock store refuses any movement of the product merged away),
  `Demo` (« Essayer la démo »: a visitor with no account claims a `demo_sandbox`, a whole company written by
  `Dataset/DemoDataset` and owned by a throwaway account; the pool is topped up by `KeepSandboxPool`, its builds on the
  `demo` transport, which the `demo-worker` container alone consumes, so no mail waits behind one; `DemoAccountGuard`
  refuses what would make the account a real one (403 `demo_refused`); a mail to a sandbox's owner is kept by
  `CaptureDemoMailListener` for « Courriers de la démo », never sent, and `DemoMailReachTest` names every mail writer;
  every PDF carries the « DÉMO » band, and Factur-X, the TEJ withholding declaration and every export declared
  `ExportForTheBooks` (the accountant's journals and tax summary) are refused; a sandbox goes at sign-out or after `app.demo.idle_ttl`
  away, taken by `TendDemoSandboxesEveryFiveMinutes`; operators' lists and the data checks leave sandboxes out through
  the `DemoCompanies` and `DemoAccounts` ports; `demo.enabled` and `demo.sandboxes_max` are the platform's « Démo » card, `demo.enabled` off by default where `APP_INSTALLATION=onprem` (`compose.onprem.yaml`), on otherwise, and refused at the first read if neither),
  and the modules one level down in `api/src/Module/<Name>/`, the scanner among them (`Module/Scanning`: a phone lent
  to a computer tab as a scanner, `scan_pairing`, the tab's endpoints under its company and the phone's four public ones
  behind its key, named in `UnauthenticatedSweepTest`, which check the module themselves through `ScannerSwitch`), and the reports (`Module/Reports`: a module declares its reports with `DeclaresReport` in its own `Infrastructure/Reports/`, each read with its subject's permission; `RunReport` reads a period against another (`ReportComparison`), `ReportRequest` parses the question for the screen and for `ReportExportController` (`…/reports/{key}/export.{csv,xlsx}`, behind a step-up, audited as `report:<key>`); a column's `ReportColumnKind` says how it reads, `Days` a span of days; a row's `ReportDrill` names the list it opens and its filters, or none for its documents; activity leaves out `LoginAudit::PREFIX`'s events), and the orders to vendors (`Module/Purchases`: draft, sent, partly received, received, closed, cancelled, numbered when sent from the `purchase_order` series; a receipt, `GoodsReceipt`, moves the stock through the module's own `StockIntake` port inside the order's transaction, so a line the stock refuses refuses the whole receipt; `ReceivingPlaces` answers where the goods go, `GET …/purchase-orders/{id}/receiving`, with `purchase.receive` alone; a price or a cost is read and written only with `product.cost.read`; what an order comes to is `PurchaseOrderTotals`, which the screen and the printed order share; the printed « Bon de commande », `PrintPurchaseOrder` at `…/purchase-orders/{id}/pdf`, is rendered on request, never stored, printing once sent from what sending kept (`PurchaseOrderPrint`: its language, formats and design, and its units' names under `document.names_as_issued`), as a sent quote does, and answered only with `product.cost.read` beside `purchase.read`; a line keeps the vendor's own code for its product, `OrderProducts::vendorCodes` (its supplier code entering one piece), read again on every draft save and when sent, never typed, and read only with `product.cost.read`) (a module reaches another only through a port it owns in its
  `Application/`, answered in the other's `Infrastructure/<Caller>/`, such as `Inventory/Application/ReceiptCost/ReceiptCosts`;
  `tests/Architecture/ModuleBoundariesTest` reds on another module's class that runs, `AllowedJoinsTest` on another module's
  table its SQL names, but the reports' and watches' reads it lists until the release), `Shared` (docs/SPEC.md § 3
  "Architecture style"; `Shared/Domain/Service/CompanyOwned` marks an entity the `Shared/Infrastructure/Doctrine/CompanyFilter`
  scopes to the company a request acts for, and `tests/Architecture/CompanyColumnTest` requires it; a paged list's provider
  uses `Shared/Infrastructure/ApiPlatform/Payload/Paging`, its repository `ListOrder` and the `SEARCH_TEXT` expression its trigram
  index is built on, and `SearchIndexesTest` names the pair; an editable record's revision sends back its `version`, checked
  through `Shared/Application/RecordVersions` and refused as `/problems/record-changed`, which the web's `isRecordChanged`
  (`shared/errors/`) reads, docs/SPEC.md § 3 « Saving over someone else », `RecordVersionsTest`; a failure an interceptor has said in words is not said again: `RequestState` and the screens that catch for themselves ask `saidAlready` (`shared/errors/said-already.ts`), which reads the answer each feature refusal keeps through `refusedFor`; a write that adds is made once per `Idempotency-Key`, `Shared/Infrastructure/Submissions`, on the routes whose default `Submissions::ROUTE_DEFAULT` is set, which `SubmissionStancesTest` holds every POST to, docs/SPEC.md § 3 « One submission, one write »). Each class sits in the folder of its role (docs/SPEC.md § 3 « Folders by role »; `SourceLayoutTest`
  reds on one out of place or a folder past 25 files; `scripts/refactor/move-classes.py` moves classes and
  `scripts/refactor/layout-oracle.sh` proves the move). Domain: entities with Doctrine attributes, value objects (`Email`), repository interfaces.
  Application: use cases and ports (no framework import; `tests/Architecture/` enforces it). Infrastructure: Doctrine
  repositories, Symfony security (`SecurityUser` snapshot, `UserProvider`, handlers, listeners, `CsrfRequestListener`),
  API Platform resources (`Me`) and the OpenAPI decorator, the console command, the session handler. Every port has one
  adapter and Symfony aliases them (`services.yaml` scans `src/`); policy values are parameters there.
- `api/translations/*.{fr,en,ar}.yaml` — the only strings the API itself emits: the invitation mail (`emails`), fiscal
  labels and mentions (`fiscal`), the « À vérifier » note an import leaves on a record (`imports`), and printed documents (`pdf`, laid out in `api/templates/pdf/`, whose `_document.css.twig` every document shares with the
  built-in layouts and the accent, and rendered by
  Gotenberg). `ApiTranslationParityTest` keeps each pair's keys identical. Everything a person reads in the SPA lives
  in `web/public/i18n/` instead, with its own parity test.

## Gates (`scripts/gates/`)

The gates holding the API and the web together (`effect-kinds.sh`, `typed-emails.sh`, `language-literals.sh`,
`value-direction.sh`, `stored-items.sh`) are listed in `web.md`.

  - `row-defaults.sh` (a raw read never falls back to a value of its own: in a file reading rows straight from the database, `is_numeric($x) ? (int) $x : 0`, `$row['q'] ?? 0` or `\is_array($found) ? $found : []` is refused; read through `Shared/Infrastructure/Doctrine/Rows`, which throws on what the query does not name, or say why with `// row-default: <why>`; a floor of 60 files reading rows)
  - `database-clock.sh` (no query decides on PostgreSQL's `now()` or `CURRENT_DATE` where the application decides on its own `ClockInterface`: the moment goes in as a parameter, or the line says `// database-clock: <why>` (`--` inside SQL), as the health check's queue age does)
  - `app-clock.sh` (its PHP half: no read of the system clock in `api/src`, a bare or relative `new \DateTimeImmutable()`, `time()`, `date()`, `strtotime()`: every moment comes from `ClockInterface`, a domain method taking it as a required parameter, but where the line or the one above says `// system-clock: <why>`, as the session handler and the rate limiter's wait do; a floor of 2,418 files read, 3 below today's count, so losing any directory of `api/src` reds)
  - `day-parsing.sh` (a day parsed with a format reads back as written: `createFromFormat('!Y-m-d', '2026-13-45')` rolls into the next year rather than failing, so a written day or moment is read through the one canon, `Shared/Domain/Service/WrittenDay::parsed($text, $format, $zone)`, null for one that does not read back; every other `createFromFormat` and `date_create[_immutable]_from_format` in `api/src` is refused unless its line or the one above says `// day-parse: <why>` (a moment the application wrote), and the helper must still compare; a `new \DateTimeImmutable($day)` is left to the `Assert\Date` on the property it reads; a floor of 15 read through it)
  - `float-casts.sh` (no `(float)` or `floatval` in `api/src`: money is a decimal string, and a cast that carries none says so on its line with `// float: <why>`)
  - `sort-directions.sh` (every query order in `api/src` and `api/tests` says its direction with PHP's `\SortDirection`, as Doctrine's upgrade notes ask: no 'ASC', 'DESC' or null to `orderBy`, `addOrderBy`, `Expr\OrderBy` or an `#[ORM\OrderBy]` mapping, which Doctrine ORM 3 deprecates, but where the line says `// sort-direction: <why>` (DBAL's builder takes a string); `ListOrder::direction` turns a request's `asc` or `desc` into one; a floor of 101 uses, so losing the largest file of them reds)
  - `order-ties.sh` (no order in `api/src` ends on a moment, `createdAt`, `ends_at`, `at`, `issue_date`, `day`, which two rows can share: a query builder chain's last `orderBy`/`addOrderBy` and every `ORDER BY` in a SQL or DQL string, an inner query's on its own, put the id last, or the line or the one above says `// order-tie: <why>`; a floor of 150 orders read)
  - `list-limits.sh` (every list a request writes to an API resource is bounded: `#[ListLimit(…)]`, the operator's `app.list_limits` read through the `ListLimits` port (`document.lines`, 1,000, on every document's lines, and `ManageInvoices` holds a draft built from delivery notes to it), or `Assert\Count(max: …)`, or its block says `// count: <why>`; a floor of 35)
  - `typed-trims.sh` (what a person typed keeps no invisible character at its ends: every one-argument `trim()` in `api/src` goes through `Shared/Domain/Service/TypedText::trimmed()`, which takes every space and format character (`\p{Z}`, `\p{Cf}`: no-break and zero-width spaces, byte-order and direction marks), or its line says `// trim: <why>`; a `trim()` given its own characters is another operation; a floor of 150)
  - `additive-migrations.sh` (after the newest release tag `vYYYY.MM.DD.N`, no migration's `up()` drops, renames or narrows what that release reads, unless its line says `// contract: <why>`: a shop's unhealthy update starts the release before it on the new schema; a statement is read whole over its lines, a released migration edited or deleted, a new CHECK, UNIQUE, EXCLUDE, trigger or foreign key on columns it does not add on a table the release holds, a rule whose table is not written out, and `CONCURRENTLY` are refused too; every method but `down()` is read as `up()`; judged against the newest release HEAD descends from; nothing is checked before the first tag)
  - `xml-reads.sh` (XML from outside is parsed only where a declared document type is refused first: every XMLReader, SimpleXML, DOM load, `xml_parse` or openspout XLSX/ODS reader in `api/src` sits in a file the gate allows with its reason, today `OpenSpoutReader`, which refuses a workbook any part of which declares one, and `XlsxPercentCells`, which it alone calls; an allowed file that parses nothing any more reds too)
  - `test-issue-shortcut.sh` (a test builds an issued invoice, a sent quote, a validated note or a sent order through the use case: an SQL statement in `api/tests` setting one's status, or DBAL's table API, `->update`/`->insert('<table>', ['status' => …])` read whole over its lines, is refused unless its line or the one above says `// issue-shortcut: <why>`, as a data check proving it notices a state no use case makes does; a floor of 702 test files read, 3 below today's count)
  - `test-clock.sh` (a day a PHP test sends or counts from goes through `ApiTestCase::dayOf`, `dayAsDate` or `apiNow`: no DateTime from a clock word or a day shift, no `date()` or `strtotime()` in `api/tests`, but a moment and a line saying `// test-clock: <why>`)

## Lessons

- A raw DBAL result is `mixed` under PHPStan max and a bare cast is refused: read it through a validating helper
  (`Shared/Infrastructure/Doctrine/Rows`) that throws on the unexpected. A sort or uniqueness check over numbers must partition by
  the series (company, establishment, document type), or another company's own numbers interleave (2026-09-29).
- `doctrine:fixtures:load` (`make fixtures`; or `make tools CMD='cd api && bin/console doctrine:fixtures:load --append'`, on the
  host's network) needs Mailpit (`docker compose up -d --wait mailpit`, SMTP on :8093), or it dies
  mid-load with a connection refused; Centrifugo being down only warns (2026-09-29).
- `make api-openapi` exports from the dev cache, so a stale `api/var/cache/dev` exports an OLD schema and the web gate
  fails locally while CI (fresh cache) passes, or the reverse. After an API resource change: `make tools CMD='cd api && bin/console cache:clear'`
  before `make gate-web` (2026-09-13: `Me` exported without `mfa`, three days after `MeMfa` landed).
- API Platform's metadata pools survive `cache:clear`: a property added to a resource is then silently dropped on write and
  missing from the OpenAPI export. After a resource property change run `make tools CMD='cd api && bin/console cache:pool:clear --all && bin/console cache:pool:clear --all --env=test'` (dev and test) (2026-09-14: `customFields` arrived empty until the pools were cleared).
- A `@var list<string>` on an API Platform property refuses nothing: a JSON object is denormalized with its keys and
  stored as a JSON object. A writable list property carries `#[Assert\Type('list')]` (2026-09-14: `choices` and
  `defaultTaxComponentIds` both accepted `{"first": …}` with 201).
- The API runs in FrankenPHP's worker mode: one kernel serves many requests. A service keeping anything a request set
  after construction implements `ResetInterface`, or it reaches the next person's request; `WorkerModeTest` runs requests
  on one kernel as the worker does (2026-09-27, row 167), and `StatefulServicesTest` reds on any service of the test, dev
  or production container writing a property outside its constructor that neither resets nor is listed with its reason.
- Never name a PHPUnit helper `run()`, `count()` or `name()`: all are final on `TestCase` and the whole file fails to load.
- `\DomainException` extends `\LogicException`, so a test catching `\LogicException` also passes on every domain refusal
  (`InvalidInvoice` and the like): assert the refusal is not the domain one, or the guard under test can vanish unseen
  (2026-09-15: a credit note of another invoice looked refused while only its amount was). `NamedLogicRefusalsTest` now
  refuses an expected or caught `\LogicException` that is not named by its message.
- BrowserKit adds a same-origin `Referer` from its history to every request, and Symfony's CSRF manager accepts it
  as origin proof: a functional test of a cross-site request must set `Sec-Fetch-Site: cross-site` and a foreign
  Referer, and a "no origin at all" request needs `getHistory()->clear()` as well as empty server parameters.
- An array under `api_platform.defaults` (`output_formats`, `normalization_context`…) is MERGED into an operation's own
  value, never replaced by it: a default `output_formats: json` left a list declared JSON-LD answering plain JSON first.
  Restrict per operation, or through a metadata factory decorator (`JsonLdOnlyWhereNamed`, 2026-09-17).
- An entity's property default must be a literal, never another class's constant (`= Other::X`): the class then needs
  its defaults resolved at runtime, Doctrine's lazy ghosts skip that, and the local debug PHP aborts the whole PHPUnit
  run in `zend_lazy_object_init` (CI's release PHP does not assert, so only the local gate shows it). 2026-09-13.
- Judge a rendered PDF by looking at it: `magick -trim -format '%@'` answered `+0+0` on pages whose content was visibly
  inset, which read as "Gotenberg ignores its margins". What was wrong was a template's `@page { margin: 0 }`, which
  overrides the renderer's margin fields in Chromium: every delivery note and invoice printed flush to the paper's edge
  (2026-09-14; `PdfTemplateMarginsTest` pins it).
- A mutated migration mutates its `down()` too: migrate the test database down before applying the mutant, and down with the
  mutant before restoring it, or what the mutant removed never leaves the schema (2026-09-15: a dropped CHECK stayed, and read green).
- `KernelTestCase::ensureKernelShutdown()` BOOTS the kernel to read its container for the cache directories, so a kernel a
  refusal left assigned but unbooted is booted again by the teardown — and refused there, turning a passing case into an
  error in a method that never asked for a kernel. Drop it instead (`static::$kernel = null; static::$booted = false;`)
  when testing anything that refuses to boot (2026-09-16, `DevelopmentKeysTest`).
- A Doctrine inverse `OneToMany` is filled by a LOAD, so an in-memory repository never fills it and a unit test reads it
  empty. A domain rule that reads an entity's siblings — what a credit note's withholding leaves for the next one —
  needs the inverse side maintained in the entity beside the owning assignment, as the other collections here are
  (2026-09-16, `Invoice::creditNoteFor`). Adding the inverse side alone is a mapping change, not a migration: the
  owning column and its index already exist.
- php-cs-fixer rewrites a `/** @var */` above a `static $x = []` inside a method into `/* @var */`, and PHPStan then
  ignores it and reports `return.type` on what the method returns. Cache in a typed `private static array` property
  instead of a static variable (2026-09-20).
- `audit_log.at` is a `timestamp(0)`, so several writes inside one second tie: assert the SET of audit actions, never
  their order, or the case passes or fails by the second it happened to run in (2026-09-20).
- An audit entry is also the realtime signal — `DoctrineAuditTrail` stages each one as a live change whose kind is the
  entity type. A use case that records nothing leaves `reloadOn([...])` on its screen permanently dead, with no error
  anywhere (2026-09-20, `ManageRoles`).
- Assigning to a typed public array property (`$recorder->staged = []`) narrows PHPStan's view of it to `array{}`, and
  the next method call widens it back to the NATIVE `array` — the `@var list<X>` is gone, so every read after that is
  `mixed` and a `$x->id?->toRfc4122()` on it errors while the identical line in a test that never reset reads fine.
  Assert over what is there rather than clearing first (2026-09-21, `KeepStockTest`).
- A promoted `public readonly ?string $code` on an `\Exception` subclass is a FATAL redeclaration at class-load time
  (`\Exception::$code` is not readonly), reported nowhere near where it is written — and rtk condensed that fatal to
  `PHPUnit: ok`. Name it anything else, and read a suite's own tally through `rtk proxy` before believing a pass.
- A nested API Platform resource serializes as an IRI, not as an object: a property holding other resources needs
  `#[ApiProperty(readableLink: true)]` AND the nested resource's own read group in the operation's
  `normalizationContext`. Without both, a POST that answers what it created answers `/api/.well-known/genid/…`.
- Run PHPStan through `composer stan` (what `make gate-api` does), never bare — the script warms the test
  container XML first, without which the Symfony extension resolves service types as `mixed` (2026-09-22).
- `DateTimeImmutable::createFromFormat('!Y-m-d', '2026-13-45')` does not fail, it rolls into the next year: read a day a request sent through `WrittenDay::parsed`, which compares `format()` with the input. A functional test probing database constraints runs inside the suite's own transaction, where the first violation aborts the rest: open a nested `beginTransaction()` (a savepoint) per probe and `rollBack()` it (2026-10-02, price lists).
- A `RequestEvent` listener that asks `Security::getUser()` on every API path makes the lazy firewall read a session for an
  anonymous request too, and Symfony then answers it `max-age=0, must-revalidate, private`: return first unless
  `$request->hasPreviousSession()`. Only the e2e of `/api/health`'s header saw it (2026-10-06, the customer-screen lock).
- The API suite runs on paratest (`api/tests/parallel.php`). The rate limiters' pool is `cache.app`, under `%kernel.share_dir%`
  (`APP_SHARE_DIR=var/share`), not the cache dir: a per-worker cache dir alone left eight workers sharing it, and thirteen limiter
  tests failed. A worker's container keeps the paths it was compiled with: after changing them, delete `api/var/cache/paratest-*` (2026-10-14).
- PHP's mbstring has no Windows-1256 (Excel's Arabic CSV): `mb_convert_encoding(…, 'Windows-1256')` throws; glibc's iconv
  `CP1256` converts it. mbstring maps Windows-1252's five undefined bytes to C1 controls, where iconv fails on them, and keeps
  a UTF-16 byte-order mark as EF BB BF; a lone UTF-16 high surrogate becomes « ? » (2026-10-16, `CsvTranscoder`).
- openspout hands over neither a cell's number format nor a column's style: a cell Excel shows as « 5 % » arrives as 0.05
  (`XlsxPercentCells` reads `styles.xml` for it), and a template column formatted as Text is a `<col style>` the writer adds
  once openspout has closed the file. A row whose cells are all empty is never written, so formatting empty rows in
  advance is no way round it (2026-10-16).
- Move classes with `scripts/refactor/move-classes.py` and prove the move with `scripts/refactor/layout-oracle.sh`. The mover
  rewrites names and `src/<path>` strings only: a path relative to `src/Module` (`ModuleBoundariesTest`'s keys) and a gate
  test's `mkdir` of a fixture folder are followed by hand. A class moved into a folder of its own changes API Platform's
  discovery order, which the oracle reads as another order, not another content; two routes that can match one request
  must not change places (2026-10-16; flagged for the next expertise refresh).
- Doctrine reads `Query::HINT_REFRESH` with `isset()`: `setHint(HINT_REFRESH, false)` still refreshes, so a mutant flipping it
  to false is equivalent and survives. Sabotage a locked re-read by removing the hint line (2026-10-17).
- A test placing a row on a clock the database reads with `now()` must use that clock: inside the test's transaction it
  stands at the transaction's start, which DAMA opened at the previous test's teardown, before PHP's clock. A TIMESTAMP(0)
  column rounds half a second up: cut with `date_trunc('second', now())`. DeepHealthTest failed 4 runs in 10 on both (2026-10-18). The reverse holds too: a row a test writes « after » an API request is stamped `self::apiNow()` (`ApiTestCase`), never
  `now()`, which reads as before a request made in the next second (`ProductMergeTest` red one run in three, 2026-10-21).
- Symfony's `Request::create`, under every BrowserKit test request, sends `Accept-Language: en-us,en;q=0.5`, and the API now
  answers in the language a request names: `ApiTestCase` sends `fr` as the web does, and a test of another language sets it
  per request. A screen word the API writes follows the request, never `User.locale`, which only mails read (2026-10-19).
- A default added to a shared declaration reaches every declaration written before it: `RecordSubject`'s merged-away
  default of « alias » made every product delete ask for a column products lack, and only the product suite saw it.
  Run the suites of the existing declarers, not only the new one's; prefer a default that does nothing (2026-10-20).
- A day a test sends or counts from is the company's, `ApiTestCase::dayOf($company, '-3 days')`, never PHP's `date()` or
  `new \DateTimeImmutable('today')`: between 23:00 and 00:00 UTC Tunis is already on the next day, the API reads the
  company's, and five suites went red on CI only in that hour. Run the whole API suite inside that window to find the
  class's members (2026-10-21). A test's day helper reads the clock once per test (`$this->today ??= …`): read again
  for the expectation, it crossed midnight in Tunis after the request and expected the next day (2026-10-26).
  `test-clock.sh` refuses any other read of a day in `api/tests`.
- Measure a request's memory with `memory_reset_peak_usage()` first and `memory_get_peak_usage()` (not `true`) after: a
  FrankenPHP worker thread keeps the memory earlier requests took, so the real size read 123 MB for 39 rows. In development
  the profiler's collectors (Doctrine's queries, Monolog's records) hold most of a long request; they are capped now, and
  production keeps none, so a development peak is an upper bound, never production's figure (2026-10-21).
- A rate limiter is spent by a person's whole path, not by one endpoint: putting the setup confirms on `mfa_verify` let a
  step-up, an enrolment, a recovery-code sign-in, a step-up and one typo exhaust it, and the right code answered 429 (red
  on CI only, 2ccf0bb7). Before giving an endpoint a limiter, list every endpoint already on it and run every e2e walking
  more than one of them (`git grep` the limiter's name over `api/src`, the routes over `web/e2e`) (2026-10-25).
  `LimiterPathsTest` declares every method spending each limiter with its family, and reds on one undeclared.
- A numbering series' row lock already serializes two sales of one kind, so a race of two notes cannot tell whether the
  stock's own lock is held: race a delivery note against an invoice (two series) to prove it (2026-11-01, S1.2).
- A container compiled without debug is never checked against the source, and `bin/console` reads no test bootstrap:
  the race harness clears its cache with `APP_CACHE_DIR` passed in, or racers boot the last run's container (2026-11-01).
- A late answer undoes a newer state: Symfony's session listener deletes the session cookie whenever a request came with
  one and its session reads empty, and the browser deletes by NAME, so a read sent before a sign-in and answered after it
  signed the person out (CI-only, the trace's cookies showed it; `KeepSessionCookieListener` now drops every deletion).
  Read a lost-session e2e through the trace's Cookie and Set-Cookie headers per request, never the screenshot (2026-11-07).
