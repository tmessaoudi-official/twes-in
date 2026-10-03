---
name: domain-symfony-api-platform
description: Use when a task touches the twes-in PHP API - Symfony 8.1, API Platform 5 resources/paging/JSON-LD, Doctrine on PostgreSQL 18, hexagonal contexts, CompanyGuard/voter tenancy, audit, FrankenPHP worker mode, PHPUnit/PHPStan max. How an expert works here - rules, tools, evidence, failure modes.
---
Review date: 2026-10-03   Validation mode: advisory   Core: .claude/rules/expertise-core.md

## Roles and mental models
- **Hexagonal architect**: Domain/Application know no framework; Infrastructure adapts; ports in Shared/Application (Transactions, DomainEvents, PdfRenderer, RealtimePublisher, Notifications, CurrentCompany). Only carve-outs: Doctrine Mapping/Types/Collections in Domain; `Psr\Clock` and Symfony DI attributes in Application.  [T 2,8.1; D 2026-09-09, DI carve-out]
- **API Platform maintainer**: the ApiResource is a SEPARATE plain `<X>Resource` with own provider/processor, never the Doctrine entity; the declaration is the contract.  [T 2,8.2]
- **Tenancy/security engineer**: authorization is `CompanyGuard::companyForActing(id, permission)` as the first line of every provider/processor (it also switches the `company` SQL filter on; filter is off by default, off again at kernel.terminate). `is_granted("ROLE_USER")` only means "logged in".  [T 8.3; D tenancy]
- **PostgreSQL DBA**: hydrated types and query shape decide correctness at scale; read the SQL; plain SQL escapes the company filter, so each raw read must name its company.  [D 2026-09-24 summary source]
- **Operator of a monolith**: FrankenPHP worker mode in EVERY target (dev, CI e2e, prod; restart every 500 requests) so leaks show before prod.  [D worker]

## Standards and rules
| Rule | Applies when | Source | Last checked |
|---|---|---|---|
| One `final readonly` use class, single `handle()`, body in `Transactions::run`, audit row in the SAME transaction, clock injected | any state-changing use case | T 2,6; AuditedChangesTest | 2026-10-02 |
| Events recorded by aggregate (`releaseEvents()`), published through `DomainEvents` AFTER commit; listeners (`#[AsEventListener]`, consumer module) catch `\Throwable`, log, notify, never fail the publisher | cross-module reaction | D 2026-09-14; T 2 | 2026-10-02 |
| Entity: private ctor, named factories, `Uuid::v7()`, `\DateTimeImmutable`, `$now` passed in, DB constraint (partial unique index) behind each domain rule | new/changed entity | T 2 | 2026-10-02 |
| Repository interface in Domain, `Doctrine<X>Repository` in Infrastructure; finders `of<Key>` / `of<Key>InCompany` return null for another tenant's row | new query | T 2,3 | 2026-10-02 |
| `company_id` on every business table; entity implements `CompanyOwned` | new table | CompanyColumnTest | 2026-10-02 |
| Three validation layers: Assert on Resource scoped `groups:[WRITE]` + `validationContext`; domain `\DomainException` (no `Exception` suffix, `Invalid*` carries `$field`); use-case rule exceptions. Processor maps explicitly: taken -> 409, `Invalid*` -> 422 `"field: msg"`, always pass `previous`; no global exception listener | any write endpoint | T 5 | 2026-10-02 |
| Refusals need a machine code, never match on English text: a bare 409 made `products-api.ts` read every conflict as `reference_taken`; import refusals carry `reason` (PHP) / `code` (JSON) | new refusal | D 2026-09-24 | 2026-10-02 |
| `api_platform.error_formats` narrowed whenever `formats` is, else `Accept: */*` gives 500 | formats change | D 2026-09-09; MembersTest | 2026-10-02 |
| Read and write shapes differ => explicit read group AND write group | new resource | D 2026-09-10 | 2026-10-02 |
| Filters are `QueryParameter`s, not `ApiFilter`; search = `pg_trgm`+`unaccent` GIN via IMMUTABLE `search_text()`, from 3 chars | list filter/search | D 2026-09 | 2026-10-02 |
| Paged lists: total via paginator, 25 default / 100 max; histories (audit, stock moves, notifications) use cursor paging; count ids with `setUseOutputWalkers(false)`, no fetch join (default DISTINCT walker took 8.4 s at 1m invoices); partial paging past ~100k rows/company | any list | D scale findings; PaginatorCountTest | 2026-10-02 |
| JSON-LD only on collection operations (metadata decorator, since array defaults merge); single records plain JSON | list ops | D; C Lessons (mechanism only) | 2026-10-02 |
| Permission = dotted `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`, exact match or `*`, no prefix implication; declared beside `DeclaresModule`, labelled fr+en (permission-labels gate); one role per membership; `platform.*` only for operators | new permission | A 2 | 2026-10-02 |
| `PermissionVoter` judges subscription (full/read-only/locked) against the permission asked, never the role's grants; non-active company = same 404 as a stranger; operators get 404 on company data (membership only, no operator pass-through) | access change | A 2; D 2026-09-15 | 2026-10-02 |
| Audit `changes`: field NAMES not values for banking/private-person data; unchanged revision records nothing; support access is owner-granted, time-boxed, read-only, audited | audit payload | D 2026-09-13/14/17 | 2026-10-02 |
| Migration: `Version<ts>`, real `down()`, raw `addSql`; mapping must equal migrated schema (`SchemaInSyncTest`) | entity change | D 2026-09-14 | 2026-10-02 |
| Times UTC stored; company zone for display and "which day" logic; tests spell the edge (23:30 UTC is already the 21st in Tunis) | dates | T 8.17; D 2026-09-10 | 2026-10-02 |
| Comments say WHY, no dates, no SPEC row numbers; official docs for every tool, departure recorded in SPEC section 7 | any edit | D 2026-09 process | 2026-10-02 |

## Tools of the trade (as configured)
- `composer gate` (run it as `make gate-api`, in the `tools` container) = `lint` (php-cs-fixer `@Symfony`+`@Symfony:risky`, strict_types, forced two-line SPDX header) -> `stan` (`cache:warmup --env=test`, phpstan `level: max` on src/ AND tests/, phpstan-symfony reading the test container XML, no baseline, 0 ignores) -> `test` (migrate test DB, phpunit).  [T 7]
- PHPUnit 13: `failOnDeprecation/Notice/Warning`, `error_reporting=-1`, DAMA per-test rollback (static connection). Kinds: Unit (plain TestCase, hand-written `InMemory*`/`Fake*`/`Fixed*`/`Capturing*` doubles in tests/Support, NOT mocks), Integration (KernelTestCase, real Postgres), Functional (`ApiTestCase`, KernelBrowser), Architecture (rules as tests).  [T 4]
- Architecture tests are the deptrac equivalent: LayerDependenciesTest, CompanyColumnTest, AuditedChangesTest, ApiResourceStateTest, DoctrineMappingTest, SchemaInSyncTest, UnauthenticatedSweepTest (walks the router: every `/api` route 401 without session, public list must equal firewall PUBLIC_ACCESS, never 5xx), PaginatorCountTest, ComposePortsTest, WorkerModeTest, SearchIndexesTest.  [T 4,7; D]
- Repo gates `scripts/gates/*.sh` (each with a test in scripts/gates/tests, run before the gate): version-pins (one version, several files), permission-labels, setting-labels, licences, spdx-headers, executable-bits, production-image.  [T 1,8.11]
- Scale baseline: company `AUDIT-EXT-2026-09-23` in db `twes_scale` (`app:scale:generate` refuses non-`*_scale`/`*_test*` DBs); SCL-01 caps the home page query count.  [D 2026-09-24]

## What good looks like (checkable)
- Failing test first, red for the stated reason; Functional test per endpoint: happy, validation (422), unauthenticated (401), wrong company (404 not 403), insufficient permission.  [T 4,8]
- New permission appears in catalogue, both label files, and a role grants it deliberately (existing custom roles are NOT auto-granted).  [D product.cost.read; A 2]
- A new list: paginator count test green, ordering index named, search expression covered by SearchIndexesTest; query count asserted where a home figure reads it.  [D]
- A new port with an external dependency has a Fake registered in `when@test` services; no test touches Gotenberg/HIBP/Centrifugo.  [T 8.9]
- Entity change ships a migration with `down()`; SchemaInSyncTest green; test DB migrated both ways.  [D 2026-09-14]
- Every audited command: one commit with its audit row; stock changes keep running totals inside the same transaction.  [D audit triage]

## Classic failure modes (not in CLAUDE.md Lessons) and detection
- Application/Domain imports `Psr\Log`, EntityManager or Symfony Clock - LayerDependenciesTest scans `use` and inline FQNs.  [T 8.1]
- `#[ApiResource]` on an entity, or API Platform collection queries - bypasses the tenant filter; ApiResourceStateTest.  [T 8.2]
- Provider/processor missing the `CompanyGuard` first line - filter stays off, other tenants' rows readable; detect with a wrong-company Functional test, not the voter.  [T 8.3]
- Plain SQL / DBAL read without company predicate - escapes `CompanyFilter`; grep each raw query for `company_id`.  [D 2026-09-24]
- Audit row written outside `Transactions::run` or `new \DateTimeImmutable()` in a use case - InMemoryAuditTrail refuses out-of-transaction rows.  [T 8.5]
- DAMA rollback does not cover rate-limiter pool, `var/test-files`, filesystem sessions, Centrifugo pushes - tests relying on them leak.  [T 8.8]
- Adapter sets a managed child while the import detaches its parent: next flush treats the parent as new (500 on two `home_location` cells) - write the three-row test before trusting the one-row one.  [D import]
- Wrong-second-factor and password both count toward the 5-failure lockout; `SecondFactorLockout` refuses with 401 `account_locked` BEFORE the `mfa_verify` limiter consumes; a limiter paces, never stops guessing.  [D 2026-09]
- Mutating request holding the session lock: only mutating methods take the advisory lock in `PostgresSessionHandler`; a GET that writes the session reintroduces waits.  [D 2026-09-27]
- Version bump in one file only - version-pins gate; Postgres tag, `serverVersion` in every DATABASE_URL, PHP tag/extensions, Symfony minor.  [T 8.11]
- Production boots with committed dev keys (`APP_MFA_KEY`, `REALTIME_TOKEN_KEY`) - `DevelopmentKeys` in `Kernel::boot()` refuses.  [D]
- Stock under concurrency (advisory locks) is NOT certified under two connections; login throttle counters are per-container (filesystem cache).  [D known issues, via v2]

## Evidence surfaces (what counts as proof)
- Everything runs in the `tools` container through `make` (CLAUDE.md § Run everything in Docker), never on a host PHP.
- Behaviour: the phpunit run's own tally line (`OK (n tests...)`), never a pipeline exit; `make gate-api` green = lint+stan+test; PHPStan only via `make tools CMD='cd api && composer stan'`.  [T 7; C]
- Architecture/tenancy: the named Architecture test red under a sabotage that lands (breaks the condition, not the feature), green after byte-exact restore.  [C Lessons; global sabotage-check]
- Scale: measured numbers on `twes_scale` (count time, query count), not reasoning.  [D scale findings]
- CI is the arbiter for PHP release vs local debug build; `/api/health` + `production-image.php` prove the prod image; OpenAPI exported by the api job is the web job's input.  [T 1 CI; C]
- Not proven by gates: realtime delivery, concurrency, usability; declare such dimensions uncertified.  [D 2026-09 certification ruling]

## New product idea mid-work
- Audit DDD/hexagonal layering (SPEC section 3) first: which module owns it, which ports, no module-to-module calls. Then place the idea (roadmap row, prerequisites, one-way doors) and re-ask via AskUserQuestion; do not code it immediately.  [Ruled: developer 2026-09-16, EXPERTISE s3]

## Reviewer lenses
1. **Layering and ports** - imports, resource-vs-entity split, one adapter per port, listener never failing the publisher.
2. **Tenancy and authorization** - guard first line, filter reach (raw SQL), 404-vs-403, operator/support access, subscription state, voter vs `is_granted`.
3. **Query, migration and runtime parity** - read the SQL and hydrated types, count walker, schema-in-sync, worker-mode state, debug vs release PHP, dev vs fresh cache.

## JSON-LD and paging traps (not in CLAUDE.md Lessons)
- Never add `#[ApiProperty(identifier: true)]` to a resource: it gives a 404 "Invalid uri variables".  [Observed: SPEC lists work, memory 2026-09]
- The generated TS type is `<Name>Jsonld<Name>Read` when the resource has no other JSON operation.
- A Doctrine `Paginator` is wrong with GROUP BY: read the SQL and the count walker.
- `->select('p.id')` hydrates a Uuid OBJECT in `getArrayResult`, so an `is_string` guard drops every row (empty page, right count).

## Vocabulary
| Term | Meaning |
|---|---|
| CompanyGuard / CompanyFilter | permission gate by company in the path / SQL filter on `CompanyOwned` entities |
| `Declares*` | module self-registration contracts: Module, Settings, Watch, FirstStep, Import, Report |
| ModuleOwnership | maps by `App\Module\<Name>\` prefix; a context outside owns nothing |
| Library context | context with no endpoints (e.g. Venue); HTTP surface belongs to the module |
| `DECIDED (revisit)` / `ASSUMED` | not user rulings until ratified |

## Canonical sources
- symfony.com/doc, api-platform.com/docs, doctrine-project.org, phpunit.de, phpstan.org, frankenphp.dev/docs [Unverified: URLs from r2-tech, not fetched or dated]; SPEC section 3 and section 7; docs/research/authorization.md.

## Changes vs v2
- Dropped the 28-row gotcha table: most rows are in CLAUDE.md Lessons, but the JSON-LD paging traps are NOT (measured 2026-10-02: `identifier: true` and `Invalid uri variables` occur 0 times in CLAUDE.md), so they are restored under Reviewer lenses below.
- Added standards table, tools (as configured: phpstan max, cs-fixer risky, DAMA, in-memory doubles), vocabulary, authorization model (A).
- Added tenancy/403-vs-404, post-commit events, paging/count findings, error-code rule, SchemaInSyncTest.
- Contradiction: r2-tech 8.6 found no schema-diff check, SPEC 2026-09-14 names SchemaInSyncTest; pack follows SPEC [Unverified: test file not opened].
- Contradiction: migration docblocks cite SPEC date, later ruling says comments carry no dates/rows; pack follows the ruling.
- v2 "audit entry is also the realtime signal" kept out: r2 shows signal is a post-commit publish; verify before reasserting.
