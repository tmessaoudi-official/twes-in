# infra — Dockerfiles, compose, deployment

**Not built yet. Lands in Wave 12** (`docs/plans/build-waves.plan.md`), though the local development
environment may arrive earlier if it starts costing more than it saves to hand-roll.

## Written from scratch — `invoiceninja/dockerfiles` is GPL-2.0

Copying a Dockerfile or Helm chart from it makes that artifact GPL-2.0 and obliges source disclosure
for the derivative — a *stronger* copyleft than the Elastic License the rest of upstream carries, and
one that would collide with this project's commercial licence.

The **topology** is an idea and free to reuse: php-fpm + nginx + Postgres + Redis + a queue worker + a
scheduler + headless Chrome for PDF rendering. The **files** are not. `CLAUDE.md` § "Licensing
invariants", item 7.

## What this tier owes on arrival

| Owed | Why |
|---|---|
| **Serve 200, not 404, under the Flutter web build's `fontFallbackBaseUrl` prefix** (`/assets/fonts/noto/`) | Ruled 2026-07-30 and the pointer for it used to lead here and find nothing — round 9 filed that. The Flutter engine resolves any script the bundled fonts do not cover through this prefix; with nothing behind it, a page rendering CJK, Hebrew or an emoji issues **3328 same-origin 404s in a 40-second load, uncapped**. Serving the already-vendored `NotoSansArabic-Regular.ttf` for any path under the prefix collapses that to **17 requests, 0 404s** [Verified]. Safe because a font either contains a codepoint or it does not — the substitute yields tofu, never a wrong glyph — and Arabic is unaffected, coming from its declared family. In a billing product the trigger is tenant free text, so this lands before any user data is rendered. |
| **Copy `dist/admin/3rdpartylicenses.txt` into the served web root** | `npm run build` writes it as a SIBLING of `dist/admin/browser/`, which is the actual document root — so the Angular tier's licence notices reach the artifact only if the deployment puts them there [Verified 2026-07-30: `grep -rl "Permission is hereby granted" dist/admin/browser/` → nothing]. Apache-2.0 § 4(a) and MIT both bind what is distributed, and `CLAUDE.md` § Gotchas records "beside the file is not shipped" as its own lesson. `THIRD-PARTY-NOTICES.md` says this is owed *with this tier*; round 9 found this table did not say so. |
| `docker compose config` clean, `bash -n` on every script | The infra tier's equivalent of a test suite — see `CLAUDE.md` § "Quality gate". |
| **PostgreSQL 18.4**, matching the pin | The API's money column is `NUMERIC(19,4)` and the tenancy guard is row-level security; both are server behaviour, so the server version is part of the contract. |
| **A non-superuser application role, without `BYPASSRLS`** | Row-level security does not apply to a superuser. `assertConnectionCannotBypassPolicies()` exists to fail loudly if this is got wrong. **It must run WHEN A CONNECTION IS ACQUIRED, and in CI — not once at boot**, which this row said for a round while the method's own docblock said the opposite: every question it asks is answered point-in-time, and a privileged role can change the answer under a connection that has already been certified, so "at boot" understates the exposure by the whole life of the process. Note it currently has **zero call sites** outside its own class — the wiring is owed in `build-waves.plan.md` § Wave 1. `scripts/gates/schema-tenancy.php` now refuses a runtime role that is `rolsuper`, `rolbypassrls` or `rolreplication`, which covers CI but not the running process. |
| **The runtime role must NOT OWN *any* table, and a deployment must set `DATABASE_URL_OWNER`** | `FORCE ROW LEVEL SECURITY` stops an owner *skipping* its policies; it does nothing about an owner *removing* them. A table owner can `ALTER TABLE … DISABLE ROW LEVEL SECURITY`, or add `CREATE POLICY … USING (true)`, in one statement. So migrations run as a separate, owning role and the application connects as a non-owner — **and that is now configuration rather than a deployment instruction**: `api/config/packages/doctrine.yaml` declares an `owner` connection fed by `DATABASE_URL_OWNER`, and `doctrine_migrations.yaml` pins migrations to it. **A deployment that overrides only `DATABASE_URL` does NOT fail — it silently migrates whatever the committed `.env` names, and exits 0.** This row asserted the opposite ("cannot migrate at all") for one commit, which was the third consecutive version of it to state a control nothing implemented; a reviewer proved the real behaviour by pointing `DATABASE_URL` at an unreachable host and watching `doctrine:migrations:status` report `twes_in` and succeed. There are three independent pointers at a database here — `DATABASE_URL`, `DATABASE_URL_OWNER` and the gate's `TWES_SCHEMA_DSN` — so CI can certify database #3 while migrations touch #2 and the application runs on #1, and nothing cross-checks that they agree. **A deployment must set `DATABASE_URL_OWNER` explicitly**; if genuine fail-closed behaviour is wanted, remove it from the tracked `.env` so `%env(resolve:...)%` throws. This row said "migrations run as a separate, owning role" for a fortnight while nothing implemented it, and `doctrine_migration_versions` in the dev database ended up owned by the runtime role; `scripts/gates/schema-tenancy.php` now refuses **any** table owned by it, tenant-owned or not, because a non-tenant table owned by that role proves the migration connection is wrong and the next tenant table will be too. Also required at the database level: the database itself must not be owned by the runtime role, or `public` belongs to `pg_database_owner` and the runtime role holds implicit `CREATE` — `REVOKE CREATE ON SCHEMA public FROM PUBLIC`, `USAGE` to the runtime role, `CREATE` to the owner. |
| **`REVOKE TRUNCATE` from the runtime role** | `TRUNCATE` is **never** subject to row-level security at any privilege level — it is gated only by the TRUNCATE privilege. A test pins this behaviour so nobody mistakes RLS for a defence against it. |
| **The connection must carry no pre-set `twes.tenant_id`** | A DSN can pin a session GUC with `options='-c twes.tenant_id=…'`, needing no privilege — exactly what a `DATABASE_URL` carries. Because `bind()` writes transaction-locally, that value is restored on COMMIT and the unbound path would then read that tenant's rows. Assert `current_setting('twes.tenant_id', true)` is NULL or empty at connection acquisition. |
| **Every tenant-owned table: `ENABLE` + `FORCE` + a policy with `USING` and `WITH CHECK`, and composite keys** | Use `PostgresRowLevelSecurityIsolation::policySqlFor()` rather than hand-writing the policy. FK and uniqueness checks run with row security BYPASSED, so `PRIMARY KEY (company_id, id)` with foreign keys and unique constraints on **both** columns is required — a single-column FK lets one tenant delete another's rows. |
| No product name, hostname, logo or e-mail address hardcoded | All branding is configuration from day one, so a later public deployment is a config change and not a code change. `CLAUDE.md` § "Licensing invariants", item 9. |
| **`.wasm` must be served as `application/wasm`** | `WebAssembly.instantiateStreaming` rejects any other MIME type, **and does so with no console error**, so the Flutter Web build sits at its typography-measurement bootstrap with no canvas and a blank page. A wrong MIME type here is a blank application in production with a green CI. The infra gate must assert the header, not just set it. Found while screenshotting the build — no test caught it. |
| **The runtime role must be `NOREPLICATION`** | A third role attribute, and the one that most defeats this design: `REPLICATION` grants a full *physical* read of the cluster, so `pg_basebackup` copies the heap files and row-level security is never involved. Certification round 5 recovered both tenants' rows from a base backup taken with a role that was neither superuser nor `BYPASSRLS` — its SQL was correctly policed throughout, which is what made the clean verdict convincing. Now checked by `assertConnectionCannotBypassPolicies()`. |
| **`REVOKE CONNECT, TEMPORARY ... FROM PUBLIC` on every database** | PostgreSQL grants `CONNECT` to `PUBLIC` on every new database, so a `GRANT CONNECT` to named roles is **inert** and every role can already connect. Harmless with one shared database; in the per-tenant-database mode `TenantIsolationStrategy` advertises, it makes every tenant's database reachable by every tenant's role with row-level security not involved at all. |
| **No policy may be `USING (true)`, and every policy must reference `twes.tenant_id`** | `ENABLE` + `FORCE` + a policy named `tenant_isolation` that isolates nothing satisfied every flag-based check while allowing cross-tenant reads **and** writes. The two catalogue flags cannot distinguish a correct policy from a hole; the expression has to be read. Now checked. |
| **A policed PARTITIONED table needs the policy on every PARTITION, not only the parent** | RLS on a partitioned parent does not police direct access to a partition: `SELECT * FROM invoices_2026` bypasses the parent's policy entirely. The parent carries `relkind = 'p'`, which also excluded it from the isolation check until round 5. |
| CI mirroring the quality gate tier by tier | Every job commented with why it exists and what breaks without it — house convention. |

### The runtime role must NOT hold `TEMPORARY` on the database

Added at certification round 12, which found this requirement stated in a shell comment in
`scripts/dev/provision-test-database.sh` and **nowhere else** — not here, beside its two siblings, and asserted
by no code, while `TRUNCATE`, an identically-shaped requirement, *is* asserted. "A control asserted in prose and
enforced nowhere is not a control" is this repository's most-repeated lesson.

`pg_temp` **precedes** `public` in the effective search path, so a temporary table named after a policed one
intercepts every UNQUALIFIED reference to it:

```sql
-- bound to tenant A
CREATE TEMPORARY TABLE invoices AS SELECT * FROM public.invoices;
-- connection returned to the pool, next holder bound to tenant B, ordinary application SQL:
SELECT * FROM invoices;      -- resolves to pg_temp.invoices: tenant A's rows, unpoliced
```

The leak arrives under the real table's own name, through queries no reviewer would look at twice. A temporary
table also carries no row-level security of its own and sits in no policed inheritance hierarchy, so no arm of
`assertPolicedTablesAreBeyondThisRolesReach()` can ever see it.

```sql
REVOKE TEMPORARY ON DATABASE <db> FROM PUBLIC;   -- PostgreSQL grants it to PUBLIC by default
-- and do NOT grant it back to the runtime role
```

A **NULL `datacl`** means PostgreSQL's default, which grants `TEMPORARY` (and `CONNECT`) to `PUBLIC` — so an
untouched database is the dangerous case, not the safe one. Asserted by
`PostgresRowLevelSecurityIsolation::assertConnectionCannotCreateTemporaryObjects()`, which is deliberately not
part of the composite acquisition check: the **test** database grants `TEMPORARY` on purpose, because the
column-fidelity suite needs a scratch table that leaves nothing behind. Production must not.

### No large objects, ever

`pg_largeobject` is a system catalogue and **cannot carry row-level security at any privilege level**.
`lo_from_bytea`/`lo_get` need no privilege the restricted runtime role lacks, and a large object's default ACL
is owner-only — which, because every request connects as the *same* runtime role, means **every tenant's blob is
readable under every binding**. `DISCARD ALL` does not clear them, so there is no release-time remedy either.

Blobs belong in a policed tenant-owned table or outside the database entirely. Invoice PDFs are the canonical
large-object use, so this is a constraint on Wave 4 rather than a theoretical one. Asserted by
`assertNoLargeObjectIsReachable()`, which is composed into the acquisition check.

**Remove the CAPABILITY, not only the residue — and this section is where the remedy belongs, which round 14
found it did not.** `assertConnectionCannotCreateLargeObjects()`'s docblock pointed here for the `REVOKE` below
and nothing here said it; the claim was read once and believed. Detection alone is also the wrong shape on its
own: the check throws on ANY row and is composed into acquisition, so one request reaching `lo_from_bytea`
permanently refuses **every subsequent acquisition** until a privileged role unlinks the object — a permanent
object on the hot path is an outage, not a guard.

```sql
-- Run once per database, as a superuser. PostgreSQL leaves proacl NULL on these, which means
-- "EXECUTE to PUBLIC", so a fresh cluster grants all of them to the runtime role.
-- lo_creat is the LEGACY spelling, two letters short of lo_create, and it was MISSING here until round 15 --
-- which meant this block did not remove the capability at all. It is also the one PDO::pgsqlLOBCreate() reaches
-- through libpq, so it is the spelling an application actually uses.
REVOKE EXECUTE ON FUNCTION lo_creat(integer)            FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION lo_create(oid)               FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION lo_from_bytea(oid, bytea)    FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION lo_put(oid, bigint, bytea)   FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION lowrite(integer, bytea)      FROM PUBLIC;
-- lo_import is NOT in that list on purpose: it ships with `{postgres=X/postgres}` rather than a NULL proacl,
-- so PUBLIC never held it and there is nothing to revoke. [Verified on PostgreSQL 18.4:
-- has_function_privilege('twes', 'lo_import(text)', 'EXECUTE') is false on an untouched cluster, while the
-- five above are true.] The detector still checks all six, because a cluster where somebody GRANTed it is
-- exactly the case a checker exists for -- so the detector's list being LONGER than this one is correct, not
-- a discrepancy.
```

### A connection that fails cleanup must be EVICTED, not reused

`discardSessionState()` rolls back and clears a connection when it is returned to the pool. If either statement
fails the connection is not merely dirty, it is **unknowable** — it may still carry a temporary table, a
`WITH HOLD` cursor or a `LISTEN` registration readable under whatever tenant is bound next. It therefore raises
`ConnectionMustBeEvicted`, and the pool's obligation is to **close and discard that connection** rather than
return it. Catching and ignoring it re-creates the eighth carrier in full.

The exception carries the driver failure as `$previous`, so it never masks an in-flight business exception on
the release path — release most often happens while another exception is already propagating.

### Statement text is shared between connections of the same role

`pg_stat_activity` exposes the `query` column to the **same role** with no `pg_read_all_stats` membership, and
every request connects as the same runtime role — so one tenant's request can read the in-flight SQL of another's.
[Verified at round 14, and the round-13 citation it replaces was FALSE: it claimed one connection saw the
other's `set_config('twes.tenant_id', '<uuid>', true)` verbatim. PDO defaults to server-side prepares
(`ATTR_EMULATE_PREPARES` is `false`), so `bind()`'s parameters never enter statement text — the spy sees
`DEALLOCATE pdo_stmt_00000003`. That citation also contradicted rule 2 four lines below it, which correctly
says `bind()` binds parameters; the two could not both be true. What IS visible is any literal a caller
INTERPOLATES: a spy connection read
`SELECT pg_sleep(2) WHERE 'client-dupont@example.com' <> 'x'` in full.]

Rows do not cross. **Statement text does**, and that includes tenant ids and any literal an ORM interpolates: a
client-name search, an `IN (…)` of invoice numbers, an e-mail in a filter. This is not removable for a shared
role, so it is a documented boundary owned by the cluster and the application — like cross-database `CONNECT`.

Two rules follow, and both are actionable:

1. **`application_name` must never carry tenant identity.** It is visible in `pg_stat_activity` to every
   connection of the same role, so `application_name = 'worker tenant-<uuid>'` publishes the tenant of every
   in-flight request.
2. **No statement may interpolate personal data.** Bind parameters instead — which is what
   `PostgresRowLevelSecurityIsolation::bind()` already does for the tenant id, and for the same reason:
   interpolating an identifier from a request into SQL is the shape of an injection as well as a disclosure.
