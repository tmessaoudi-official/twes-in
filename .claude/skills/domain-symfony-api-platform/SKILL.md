---
name: domain-symfony-api-platform
description: Use when a task touches the twes-in PHP API - Symfony 8.1, API Platform 5 resources/paging/JSON-LD, Doctrine on PostgreSQL 18, hexagonal contexts, CompanyGuard/voter tenancy, audit, FrankenPHP worker mode, PHPUnit/PHPStan max. How an expert works here - rules, tools, evidence, failure modes.
---
Review date: 2026-11-02 22:30   Validation mode: advisory   Core: .claude/rules/expertise-core.md
Tags: T = r2-tech, D = SPEC §7 digest, A = docs/research/authorization.md, C = CLAUDE.md; `SPEC:<n>` = docs/SPEC.md line.

## Roles and mental models
- **Hexagonal architect**: Domain/Application know no framework; Infrastructure adapts. Shared ports in `Shared/Application` (Transactions, DomainEvents, Effects, RecordVersions, ListLimits, Notifications, CurrentCompany); a context's own under `Application/<capability>/`. Carve-outs: Doctrine Mapping/Types/Collections in Domain; `Psr\Clock` and Symfony DI attributes in Application.  [T 2,8.1; D 2026-09-09; Source: git ls-files api/src/Shared/Application]
- **Source layout**: folders by role after `mtarld/apip-ddd`: `Domain/{Model,Repository,ValueObject,Event,Exception,Service}`, `Infrastructure/ApiPlatform/{Resource,State/Processor,State/Provider,Payload,Output,Security,OpenApi}`, `Doctrine/`; at most `SourceLayoutTest::CAP` files a folder; move with `scripts/refactor/move-classes.py`, prove with `layout-oracle.sh`; a class stored or routed by FQN never moves.  [Ruled: user 2026-10-16 09:47, SPEC:5300; Source: SPEC §3 « Folders by role »]
- **API Platform maintainer**: the ApiResource is a SEPARATE plain `<X>Resource` with own provider/processor, never the entity.  [T 2,8.2]
- **Tenancy/security engineer**: `CompanyGuard::companyForActing(id, permission)` is the first line of every provider/processor (it switches the `company` SQL filter on; `CompanyFilterLifter` lifts it at kernel.terminate). `is_granted("ROLE_USER")` only means "logged in".  [T 8.3; D tenancy]
- **PostgreSQL DBA**: read the SQL and hydrated types. SQL stays raw only where DQL cannot say it (runtime tables, PostgreSQL features, the catalogue, reports' jsonb); a raw read names its company and goes through one row reader, `Shared/Infrastructure/Doctrine/Rows`.  [Ruled: user 2026-10-20 15:35, SPEC:5523]
- **Operator of a monolith**: FrankenPHP worker mode in EVERY target, a fresh worker every 500 requests; per-request state resets (`StatefulServicesTest`, `WorkerModeTest`).  [D worker; Source: infra/api/Dockerfile:7-8; C Lessons]

## Standards and rules
| Rule | Applies when | Source |
|---|---|---|
| One `final readonly` use class, `handle()`, body in `Transactions::run`, audit row in the SAME transaction, clock injected | state-changing use case | T 2,6; AuditedChangesTest |
| COMMAND (one transaction, audited) or QUERY (read-only, may use SQL); no bus, Messenger only for async; split a big `Manage*` when next edited | any use case | SPEC §7 2026-10-07 23:43 |
| Events from the aggregate (`releaseEvents()`), published AFTER commit; listeners catch `\Throwable`, never fail the publisher | cross-module reaction | D 2026-09-14 |
| A module reaches another only through a port it owns, answered in the other's `Infrastructure/<Caller>/`, carrying ids and plain values, never the other's entity; a crossing that writes another module's data is a port before the release, read-only ones stay frozen in `ModuleBoundariesTest` until after | cross-module call | [Ruled: user 2026-10-20 15:17, SPEC:5514; 2026-10-21 11:30, SPEC:5559]; C |
| A report or watch may join another module's tables for reads until the release, each join listed in `AllowedJoinsTest`; after it, a port | reports, watches | [Ruled: user 2026-10-20 15:35, SPEC:5524] |
| Repository interface in Domain, `Doctrine<X>Repository` in Infrastructure; `of<Key>InCompany` returns null for another tenant's row; read and write shapes differ => explicit read AND write group | new query, resource | T 2,3; D 2026-09-10 |
| Entity: private ctor, named factories, `Uuid::v7()`, `$now` passed in, a DB constraint behind each domain rule; `company_id` + `CompanyOwned` on every business table | entity/table | T 2; CompanyColumnTest |
| Editable record: `#[ORM\Version]`, `version` answered and required on PUT and DELETE (item actions such as issue or pay carry none, guarded by status under the row lock), checked via `RecordVersions`; stale = 409 `/problems/record-changed`. A write no form made loads `FOR UPDATE` instead (Doctrine checks the version on EVERY flush) | any editable record | [Ruled: user 2026-10-16 17:17, SPEC:5308; 2026-10-17 01:14, SPEC:5340; 2026-10-17 16:04, SPEC:5343]; SPEC §3 |
| Settings save all changed keys in one PUT, one transaction, each with its row version (0 = none at that level); any collision refuses all with 409, never 500 | settings, defaults | [Ruled: user 2026-10-17 03:44, SPEC:5341] |
| `Idempotency-Key` only on the POSTs that ADD, each listed `additive` in `SubmissionStancesTest` (13 today: payments, receipts, stock movements, imports…), via `Submissions::ROUTE_DEFAULT`; creates stay outside | new POST | [Ruled: user 2026-10-21 22:14, SPEC:5597]; SubmissionStancesTest |
| Domain refusal answers as a violation through `DomainRule::refused` (field, message, reason a screen translates); a plain « field: msg » 422 needs `// plain-refusal: <why>`; one problem+json contract with a stable reason. Assert on the Resource scoped `groups:[WRITE]` + `validationContext`; domain exceptions carry no `Exception` suffix; a `*Taken` conflict maps to 409 (`ConflictHttpException`); a mapped exception always passes `previous` | write endpoint | [Ruled: user 2026-10-16 17:17, SPEC:5308] (one contract, stable reason); [Source: scripts/gates/domain-refusals.sh] (DomainRule::refused, plain-refusal); T 5, CreateCustomFieldProcessor.php:36-37 (the rest) |
| Refusals carry a machine code, never English text; imports refuse a row with all its faults, rules in the domain value objects | new refusal, import | D 2026-09-24; [Ruled: user 2026-10-09 10:40, SPEC:4927] |
| An operator value is a parameter (services.yaml), held at container build by a context's `*Bounds` pass through `ParameterBounds::{duration,whole,bool}`: outside its bounds the deployment never starts; which actions ask step-up stays code | new tunable | [Ruled: user 2026-10-09 07:46, SPEC:4900; 2026-10-13 20:22, SPEC:5176]; [Source: Shared/Infrastructure/DependencyInjection/ParameterBounds.php:15-19]; OperatorParameterBoundsTest |
| Each limiter's budget is `app.rate_limit.<name>.{limit,interval}`; every method spending it declared in `LimiterPathsTest`; list the limiter's other spenders first | rate limiter | [Source: api/config/packages/rate_limiter.yaml:1-3]; C Lessons 2026-10-25 |
| `api_platform.error_formats` narrowed whenever `formats` is, else `Accept: */*` gives 500 | formats change | D 2026-09-09; MembersTest; [Source: api_platform.yaml:26-28] |
| Filters are `QueryParameter`s; search `pg_trgm`+`unaccent` via `SEARCH_TEXT`, from 3 chars (shorter: the exact number only); its index use held by SearchIndexesTest (Integration) | list search | D 2026-09; [Source: CustomerResource.php:57; SearchIndexesTest.php:54] |
| Paged lists via `Paging`: 25 default, 100 max, client size allowed; histories cursor-paged; count ids with `setUseOutputWalkers(false)`, no fetch join; written lists bounded (`ListLimit`, `list-limits.sh`) | any list | D scale; [Source: api_platform.yaml:40-42]; C |
| JSON-LD only on collections; single records plain JSON | list ops | D |
| Permission dotted, exact match or `*`; declared beside `DeclaresModule`, labelled; one role per membership; `platform.*` operators only | new permission | A 2 |
| `PermissionVoter` judges subscription against the permission asked; non-active company and operators on company data = 404 | access change | A 2; D 2026-09-15 |
| Audit records field NAMES; actions declared via `DeclaresAuditActions` (`UndeclaredAuditAction` under debug); an unchanged revision records nothing | audit | D 2026-09-13; C; Customer.php:186 |
| Migration: real `down()`, `SchemaInSyncTest`; after a release tag `up()` only adds (`additive-migrations.sh`, `// contract: <why>`): rollback runs the previous release on the new schema | entity change | [Ruled: user 2026-10-17 17:08, SPEC:5357]; C |
| UTC stored; company zone decides the day; tests use `ApiTestCase::dayOf`/`apiNow` (`test-clock.sh`); queries take the app clock, not `now()` (`database-clock.sh`) | dates | D 2026-09-10; C |
| A mail naming an account queues only email + language; the worker makes the token: no raw token queued, known and unknown addresses cost the same | account mails | [Ruled: user 2026-10-06 02:42, SPEC:4353] |

## Tools of the trade (as configured)
- `make gate-api` = lint (cs-fixer `@Symfony:risky`, SPDX) -> stan (`level: max` on src and tests, test container XML, no baseline; result cache in the toolchain's TMPDIR, `/tmp/twes-in-tools-<uid>/phpstan`) -> test.  [T 7; Source: compose.yaml:283, Makefile:32; SPEC:5261 ASSUMED]
- `make check FILES=…` first (seconds); it runs only the named files' specs, so also run tests that `git grep -l` the changed directory or resource.  [Ruled: user 2026-10-15 14:10, SPEC:5253; C Lessons 2026-10-28]
- PHPUnit 13 on paratest (`api/tests/parallel.php`): each worker its own `twes_test` copy, `APP_CACHE_DIR`, `APP_SHARE_DIR`; DAMA rollback; hand-written `InMemory*`/`Fake*` doubles, not mocks; fails on deprecation, notice, warning (`error_reporting=-1`); kinds Unit / Integration (KernelTestCase, real Postgres) / Functional (`ApiTestCase`) / Architecture; migrations `Version<ts>` with raw `addSql`.  [Ruled: user 2026-10-14 11:02, SPEC:5182; T 4]
- Races: `make stress` (`api/tests/Concurrency`, `Race::run`, db `twes_test_stress`).  [Source: CLAUDE.md § Run everything in Docker]
- Guard tests: Architecture/ LayerDependencies, CompanyColumn, AuditedChanges, ApiResourceState, DoctrineMapping, PaginatorCount, StatefulServices, SourceLayout, ModuleBoundaries, AllowedJoins, LimiterPaths, RecordVersions, SubmissionStances, NamedLogicRefusals; Functional/ WorkerMode, UnauthenticatedSweep (walks the router); Integration/ SchemaInSync, RecordReferenceCoverage.  [Source: git ls-files api/tests, 2026-11-02]
- PHP gates in `scripts/gates/`: domain-refusals, row-defaults, database-clock, sort-directions, list-limits, typed-trims, float-casts, additive-migrations, xml-reads, test-clock, language-literals, permission-labels, setting-labels, version-pins.  [Source: ls scripts/gates, 2026-11-02]

## What good looks like (checkable)
- Failing test first; per endpoint: happy, 422, 401, wrong company (404), missing permission; editable: stale version 409.  [T 4,8; SPEC:5308]
- New permission: catalogue, labels, a role grants it deliberately.  [D; A 2]
- New list: count test, ordering index, search covered, a direction via `\SortDirection`; query count asserted where a home figure reads it (`ApiTestCase`).  [D; C]
- New port with an external dependency: a Fake in `when@test`; no test touches Gotenberg/HIBP/Centrifugo.  [T 8.9]
- Entity change: test DB migrated both ways; stock changes keep running totals inside the same transaction.  [D 2026-09-14; D audit triage]
- New parameter: its `*Bounds` pass and a test outside each bound.  [Source: OperatorParameterBoundsTest]

## Classic failure modes (not in the Lessons (CLAUDE.md and `.claude/rules/`)) and detection
- Application/Domain importing EntityManager, `Psr\Log` or Symfony Clock - LayerDependenciesTest.  [T 8.1]
- `#[ApiResource]` on an entity, or a resource on API Platform's Doctrine provider/processor - bypasses the tenant filter; ApiResourceStateTest.  [T 8.2; ApiResourceStateTest.php:14-37]
- Provider missing the `CompanyGuard` line - other tenants' rows readable; detect with a wrong-company Functional test.  [T 8.3]
- Raw SQL without company predicate or falling back to its own default - `row-defaults.sh`, read through `Rows`.  [Ruled: user 2026-10-20 15:35, SPEC:5523]
- A system writer loading a versioned row unlocked - refused as « record changed » when a person saves in the same instant.  [SPEC:5340]
- DAMA rollback does not cover the rate-limiter pool, `var/test-files`, filesystem sessions, Centrifugo pushes - tests relying on them leak.  [T 8.8]
- Adapter sets a managed child while an import detaches its parent - 500 on the second row; test three rows.  [D import]
- Wrong second factor and password both count toward the 5-failure lockout; `SecondFactorLockout` refuses 401 `account_locked` before the limiter.  [D 2026-09]
- Only mutating methods take the session's advisory lock; a GET writing the session reintroduces waits.  [D 2026-09-27]
- Production booting with committed dev keys - `DevelopmentKeys` refuses in `Kernel::boot()`.  [D]

## Evidence surfaces (what counts as proof)
- Everything in the `tools` container through `make`, never host PHP.  [C]
- The suite's own tally line, never a pipeline exit.  [C]
- An architecture or tenancy guarantee: its test red under a sabotage that lands, green after byte-exact restore.  [C; global sabotage-check]
- Scale: measured numbers on `twes_scale`; concurrency: `make stress`, racing two series where one row lock would hide the lock under test.  [D scale; C Lessons 2026-11-01]
- CI arbitrates; `production-image.php` and `/api/health` prove the prod image.  [T 1; C]
- Not proven by gates: realtime delivery, usability; declare them uncertified.  [D]

## New product idea mid-work
- Audit DDD/hexagonal layering first (owning module, ports, no module-to-module calls), place it on the roadmap, re-ask via AskUserQuestion; do not code it.  [Ruled: user 2026-09-16]

## Reviewer lenses
1. **Layering and ports** - imports, resource-vs-entity, folder by role, ports carrying values, listener never failing the publisher.
2. **Tenancy and authorization** - guard first line, raw SQL reach, 404-vs-403, operator/support access, subscription state.
3. **Query, migration and runtime parity** - SQL and hydrated types, count walker, additive migrations, versions and locks, worker-mode state, dev vs fresh cache.

## JSON-LD and paging traps (not in the Lessons (CLAUDE.md and `.claude/rules/`))
- Never `#[ApiProperty(identifier: true)]` on a resource: 404 "Invalid uri variables".  [Observed: memory 2026-09]
- The generated TS type is `<Name>Jsonld<Name>Read` when the resource has no other JSON operation.
- A Doctrine `Paginator` is wrong with GROUP BY: read the SQL and the count walker.
- `->select('p.id')` hydrates a Uuid OBJECT in `getArrayResult`; an `is_string` guard drops every row.

## Vocabulary
| Term | Meaning |
|---|---|
| CompanyGuard / CompanyFilter | permission gate by company in the path / SQL filter on `CompanyOwned` entities |
| `Declares*` | self-registration: Module, Settings, Watch, FirstStep, Import, Export, Report, AuditActions, DataChecks, RecordReferences, Erasure, NotificationKinds |
| ModuleOwnership | maps by `App\Module\<Name>\` prefix |
| Library context | context with no endpoints (Venue, Files); its HTTP surface belongs to the module (SPEC:1210-1211, AGREED 2026-09-21 06:10) |
| `*Bounds` (Infrastructure) | compiler pass holding a context's parameters within bounds (`RoleBounds` is unrelated: role ranks) |
| `DECIDED (revisit)` / `ASSUMED` | not user rulings until ratified |

## Canonical sources
- symfony.com/doc, api-platform.com/docs, doctrine-project.org, phpunit.de, phpstan.org, frankenphp.dev/docs [Unverified: not fetched]; github.com/mtarld/apip-ddd [Source: SPEC:5300]; SPEC §3, §7; docs/research/authorization.md.

## Changes since v2
- JSON-LD paging traps restored (absent from CLAUDE.md, measured 2026-10-02).
- 2026-11-02 refresh: versions, idempotency, bounded parameters, module ports, raw SQL, layout, paratest added; the « field: msg » 422 rule replaced by `DomainRule::refused`; the "audit entry is not the realtime signal" note dropped (the Lessons (CLAUDE.md and `.claude/rules/`) say it is).
