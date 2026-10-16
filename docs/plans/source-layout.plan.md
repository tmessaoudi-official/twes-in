# source-layout Plan

## Decisions Log
- [2026-10-16 09:47] AGREED: the source layout follows API Platform's DDD reference with a size rule, one architecture test enforcing it; persisted class names stay put (docs/SPEC.md § 7, same stamp).
- [2026-10-16 09:47] AGREED: order: F26 slice 3, then this move context by context, then the ruled bulk actions, then row 251's P3s.
- [2026-10-16 09:47] AGREED: a list × action matrix on a marking page and a list stance gate; lenses 54 and 55 join round 2.
- [2026-10-16 10:40] ASSUMED (review): no Contributes/ or ForModule/ folders: an extension point's folder already names its point, Inbox, Watch, Settings, Erasure, FirstSteps and DataHealth are both contexts and points, and permission-labels.sh finds manifests at Infrastructure/Module/*Module.php. Basis: advisor goal-start round 1 (narrowing fine, log it). Alternatives: Contributes/<point>/ classified by the Declares* interface.
- [2026-10-16 10:40] ASSUMED (review): Application keeps capability folders under the 25-file cap; no forced Port/, Exception/ or Permission/ folders: 146 exceptions and 89 interfaces would move for no reader, and ModuleBoundariesTest knows a contract by its kind, not its path. Basis: advisor round 1. Alternatives: the plan's Port/Exception/Permission folders.
- [2026-10-16 10:40] ASSUMED (review): the *Permission classes and guards stay in Infrastructure/ApiPlatform, under Security/: moving them to Application changes their layer, which is not a layout move. Basis: advisor round 1. Alternatives: Application/Permission/.
- [2026-10-16 10:40] ASSUMED (review): the 11 *SearchReader classes go to ApiPlatform/Payload/, not Doctrine/Query/: they read the request's query parameters into a Domain search and touch no database. Basis: CustomerSearchReader::read(array $parameters, …) read 2026-10-16. Alternatives: Doctrine/Query/.
- [2026-10-16 10:40] ASSUMED (review): Fiscal/Domain/Calculation stays one named sub-domain, allowed by an explicit list in the test: one coherent calculator under the cap, named by the Dockerfile and the expertise pack. Basis: advisor round 1. Alternatives: scatter it into ValueObject/ and Service/.
- [2026-10-16 10:40] ASSUMED (review): the move mapping is a curated file per context (drafted by the mover, edited by hand, committed with the move), and the test enforces only decidable rules (resource, processor, provider, OpenAPI decorator, entity, repository port and adapter, Doctrine type, exception, enum, DomainEvent) plus the cap; value object vs service is an allowed set. Basis: advisor round 1 item 5.
- [2026-10-16 10:41] ASSUMED (review): Infrastructure/Doctrine/ stays flat (no Repository/, Query/, Type/ folders), under the 25-file cap: the reference keeps DoctrineBookRepository and DoctrineBookViewFinder side by side in Infrastructure/Doctrine/ (gh api repos/mtarld/apip-ddd tree, read 2026-10-16), and the AGREED ruling says follow the reference. Alternatives: the plan's Doctrine/{Repository,Query,Type}.

## Formal Plan

**Measured before planning (2026-10-16, at 02abdb53).** 1,776 PHP files in `api/src`. The 26 `Infrastructure/ApiPlatform/`
folders are flat: 131 processors, 118 providers, 122 resources and 75 others (12 `*Permission`, 11 `*SearchReader`,
11 OpenAPI decorators, 10 `*Option`, 33 views, rows and answers). Largest flat folders: Inventory ApiPlatform 76, Tenancy
ApiPlatform 54, Invoices ApiPlatform 53, `Invoices/Application` 63. `Infrastructure/` mixes technologies, the extension
points a context contributes to, and the adapters it answers for another module. Doctrine and API Platform map all of
`src/`, so no mapping changes; `messenger.yaml` routes four Infrastructure FQCNs and the session holds `SecurityUser`:
those stay. `ModuleBoundariesTest` keys its exceptions by file path. Branch `w3` holds 2 commits already on master and
1 stale WIP: nothing to integrate first.

**Target** (narrowed 2026-10-16 10:40 by the ASSUMED entries above, to the reference's own folders).
- `Infrastructure/ApiPlatform/`: `Resource/`, `State/Processor/`, `State/Provider/`, `OpenApi/`, `Payload/` (inputs and
  the search readers), `Output/` (views, rows, options, answers), `Security/` (permissions, guards), `Metadata/`;
  nothing flat. A resource, processor or provider outside `ApiPlatform/` (the failed-messages page) moves into it.
- `Infrastructure/Doctrine/` stays flat, as the reference keeps it; every other Infrastructure folder stays as it is.
- `Domain/`: `Model/` (entities, embeddables), `Repository/` (ports), `ValueObject/` (and enums), `Event/` (what
  implements `DomainEvent`), `Exception/`, `Service/`; nothing flat. `Fiscal/Domain/Calculation` stays whole.
- `Application/`: capability folders under the cap; no forced `Port/`, `Exception/` or `Permission/`.
- Size rule: a folder past 25 files is split by aggregate (`Resource/StockLocation/`) or capability.
- Kept by name, never moved: the four routed messages, `SecurityUser`, the nine scheduled tasks (`SourceLayoutTest::KEPT`,
  computed from messenger.yaml, `UserInterface`, `#[AsMessage]`, `#[AsPeriodicTask]`, `#[AsCronTask]`).

**Steps.**
1. The rule first, red: `tests/Architecture/SourceLayoutTest` classifies every class by its role (suffix, `#[ApiResource]`,
   interface in Application, exception, entity, enum) and holds it to its folder and the size cap; a floor on the files it
   reads, so a glob that stops matching reds. Red on today's tree, for the stated reason.
2. The mover (`scripts/refactor/move-classes.py`, kept for later moves): a mapping file of old → new FQCN computed from
   the classifier, `git mv`, the namespace line, every reference across `api/src`, `api/tests`, `api/config` (imports,
   inline FQCNs, same-namespace names that now need a `use`), dry-run first; PHPStan max is the backstop.
3. The oracle (`scripts/refactor/layout-oracle.sh`): the OpenAPI export and `debug:router` before and after, byte
   compared; the API Platform pools cleared; `make gate-api`. A move is correct only when the export is identical.
4. Move context by context, smallest first (Audit, Watch, FirstSteps, CustomFields, Legal, ModuleRegistry, Settings,
   Fiscal, Identity, Licensing, then the modules), Inventory, Invoices and Tenancy last with their aggregate splits; one
   commit and one gate each; `ModuleBoundariesTest`'s path keys moved with their files.
5. Docs: CLAUDE.md « Where things live », SPEC § 3 Architecture style and § 6 layout, the expertise pack refreshed from
   § 7 (`/discover-expertise --write`), never hand-appended.
6. The list stance gate (`scripts/gates/list-stances.sh` with its test through `no-leftovers.sh`): every list page
   declares selection, bulk, row actions, import and export as offered or not, with its reason; red on no list found.
7. The list × action matrix on a marking page for the developer's ruling (the lists not yet ruled).
8. The ruled bulk actions: invoices, products, delivery notes (2026-10-11 08:26) and « Ajouter à… » on several products
   (2026-10-11 01:24), each with its dry-run preview, test first.
9. Round 2's brief gains lenses 54 « one pattern everywhere » and 55 « code layout and architecture conformance ».

**Risks and rollback.** Each context is one commit: `git revert <sha>` undoes it whole. A class name kept at runtime
that moves would sign everyone out or strand queued mail: those are excluded by the classifier and named in the test.
Work in flight is rebased on the new paths only after the move of its context lands.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | SourceLayoutTest, red first | M | done | 419c0077 | api/tests/Architecture/** |
| 2 | The class mover, dry run first | M | done | 419c0077 | scripts/refactor/** |
| 3 | The oracle: OpenAPI and router byte-identical | S | done | 419c0077 | scripts/refactor/** |
| 4 | Move context by context | L | done | 8217b242 | api/src/** api/tests/** |
| 5 | Docs and the expertise refresh | S | doing | 8217b242 | CLAUDE.md docs/** .claude/** |
| 6 | List stance gate | M | todo | - | scripts/gates/** web/src/app/** |
| 7 | List × action matrix for ruling | S | todo | - | var/claude/** |
| 8 | Ruled bulk actions | L | todo | - | api/src/** web/src/app/** |
| 9 | Lenses 54 and 55 in round 2's brief | S | todo | - | docs/plans/** var/claude/** |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
