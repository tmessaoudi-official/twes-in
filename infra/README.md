# infra — Dockerfiles, compose, deployment

**It exists and it runs.** This file said *"Not built yet. Lands in Wave 12"* until 2026-08-02, one commit
after the tier landed. The Wave 12 items that remain are named in the owed table below; everything else here
is real.

## Running it

Prerequisites: **Docker Engine 24+ with the Compose v2 plugin**, `make`, and `openssl`. Nothing else — no
PHP, no Node, no Flutter on the host. The images bring their own.

```sh
make env          # writes infra/.env.local with four freshly generated secrets. Run once.
make up           # installs api/vendor if missing, builds the dev image, starts the stack. First run 5-10 min.
make build-front  # DEVELOPMENT front-end bundles into the volumes the API serves. Slow first time — see below.
```

For a development loop, add:

```sh
make build-front-prod # the PRODUCTION bundles: minified, no source maps. Needed before `make up-prod`.
make debug-on         # arm Xdebug (see "Debugging with an IDE" below); make debug-off to disarm
make test             # the API test suites, inside the container
make composer CMD="require symfony/uid"   # Composer in the container, so the host needs no PHP
```

Then:

| URL | What |
|---|---|
| `http://localhost:8080/api` | the API Platform entrypoint |
| `http://localhost:8080/api/docs.jsonopenapi` | the OpenAPI document. **NOT `/api/docs`**, which returns 404 to a browser: `api_platform.yaml` deliberately ships no HTML documentation UI, because SwaggerUI and ReDoc fetch remote assets and that is a privacy question this project has already ruled on for the Flutter build |
| `http://localhost:8080/api/currencies` | the one implemented resource |
| `http://localhost:8080/health` | liveness — touches nothing |
| `http://localhost:8080/health/ready` | readiness — database, schema, tenant binding |
| `http://localhost:8080/admin/` | the Angular admin (after `make build-front`) |
| `http://localhost:8080/app/` | the Flutter web client (after `make build-front`) |

`make help` lists every target. `make logs`, `make ps`, `make shell`, `make console CMD=debug:router`,
`make psql`, `make backup`, `make down`, `make destroy`.

**`make build-front` is slow the first time and that is inherent, not a defect.** There is no official Flutter
image, so that stage downloads the SDK (~1 GB) and runs `flutter precache`. It is a build-only stage — `scratch`
at the end means none of it reaches a running container — and Docker caches it, so only the first run pays.

That is why both front-end builders sit behind Compose's `build` **profile**: `make up` does not start them, so
it stays a couple of minutes rather than fifteen. Skip `make build-front` entirely if you only want the API —
`/admin/` and `/app/` return 404 until it has run, which is honest rather than broken.

**Ports.** `HTTP_PORT` defaults to `8080` and is bound to `127.0.0.1` only, never `0.0.0.0` — the short compose
form would expose the stack to the local network, which on a laptop on café wifi is a real exposure. Change it
in `infra/.env.local` if 8080 is taken.

**Behind a TLS-intercepting corporate proxy**, drop the CA into `infra/api/ca-certificates/` before `make up`.
One certificate per file — `update-ca-certificates` refuses to hash-link a bundle. It is trusted for the BUILD
only, and the API runtime stage explicitly REVOKES it, because a container that trusts an interception CA at run
time would accept a man-in-the-middle on every outbound call, which from Wave 9 means payment gateways.

**All three tiers read that one directory**, which is right because a CA is a property of the network rather than
of a language toolchain — but only the API tier honoured it until 2026-08-05, so `make build-front` failed on any
proxied network. Each tier fails differently and none of the messages names TLS first:

| tier | symptom without the CA |
|---|---|
| API | Composer reports the package server unreachable |
| admin | `npm error errno SELF_SIGNED_CERT_IN_CHAIN` at `npm ci` |
| mobile | the Flutter SDK download from `storage.googleapis.com` returns nothing at all [Verified: `000` without the CA, `200` with it, against the exact pinned URL] |

The admin tier needs one extra thing, and it is the half that is easy to miss: **Node does not use the system
trust store.** It ships its own compiled-in root list, so `update-ca-certificates` alone changes nothing for
`npm` — `NODE_EXTRA_CA_CERTS` is what adds to Node's list without replacing it. See that directory's own README
and the comments in each Dockerfile.

## Target naming — bare means development, `-prod` means production

Developer ruling, 2026-08-05, and `scripts/gates/makefile-conventions.sh` enforces it:

> **A bare target name acts on DEVELOPMENT. `-prod` acts on PRODUCTION. No other suffix means an environment.**

That direction, and not the reverse, because of **blast radius**: muscle memory types the short name, so the short
name has to be the harmless one. `make down` and `make build-front` are typed dozens of times a day — if bare meant
production, every one of those reflexes would reach a live system.

| | development | production |
|---|---|---|
| start / stop | `up` · `down` | `up-prod` · `down-prod` |
| build the API image | `build` | `build-prod` |
| build front-end bundles | `build-front` | `build-front-prod` |
| migrate | `migrate` | `migrate-prod` |
| inspect | `logs` · `ps` · `shell` · `console` · `psql` | `logs-prod` · `ps-prod` · `shell-prod` · `console-prod` · `psql-prod` |
| data | `backup` · `restore` · `destroy` | `backup-prod` · `restore-prod` · **no `destroy-prod`** |

Two deliberate asymmetries, both stated in the gate's exemption list with their reasons:

- **There is no `destroy-prod`.** `destroy` deletes the database volume. A one-word command that does that to
  production is a foot-gun no convention should demand for symmetry's sake.
- **`install`, `composer`, `test` and `debug-*` are development-only** and carry no suffix, because Composer,
  PHPUnit and Xdebug are absent from the production image by design — a `-prod` twin could not run.

`config-prod` is the one production-only target, because rendering the dev configuration is just
`docker compose config`.

**What this replaced, since it is a trap worth knowing about.** The suffix used to answer two different questions:
`up`/`up-prod` marked which STACK a target drove, and `build-front`/`build-front-dev` marked which ARTEFACT FLAVOUR
it produced — so the bare form meant "dev" in one family and "prod" in the other. `build-front` was both at once. And
because both bundle targets write to the **same shared volume that the production stack also serves**,
`make build-front-dev` followed by `make up-prod` served an unminified bundle carrying our TypeScript source maps out
of "production". **So run `make build-front-prod` before `make up-prod`** — the flavour in the volume has to match the
stack that serves it.

A third axis had the same problem: `gate` meant "the API tier only". The rule there is the same shape — **the bare
name is the whole thing, a suffix narrows it** — so `gate` now runs every tier and `gate-api`, `gate-infra`,
`gate-make` narrow it.

## dev and prod are different ARTEFACTS, not one artefact with a flag

Developer ruling, 2026-08-04: *"dev should be an easy env to debug and test and prod should be very optimized and
closed and secure"*. Those pull in opposite directions, so where they disagree they get separate builds. Design and
rulings: `docs/plans/dev-prod-separation.plan.md`.

| | dev (`make up`) | prod (`make up-prod`) |
|---|---|---|
| API image | `dev` Dockerfile target, tagged `twes-in/api:dev-local` | `runtime` target, tagged `twes-in/api:${TWES_VERSION}` |
| Xdebug | **present**, `xdebug.mode=off` until `make debug-on` | **absent from the image entirely** |
| Composer, `gcc` | present, so `make install` and `make composer` work | absent |
| source | the whole `api/` tree bind-mounted read-write | baked in, root filesystem `read_only` |
| `vendor/` | on the HOST, installed by the container | in the image |
| OPcache | `validate_timestamps=1`, no preload, JIT off | `validate_timestamps=0`, preload, JIT tracing |
| errors | `display_errors=On`, argument values in traces | logged only; `zend.exception_ignore_args=On` |
| capabilities | Docker defaults | `cap_drop: ALL` on every service, adds established by test |
| front-end bundles | `make build-front` — source maps, unminified (1.31 MB) | `make build-front-prod` — minified, no maps (189 kB) |

**Why Xdebug forces two images rather than a switch.** A debugger in a production image is a remote-code-execution
amplifier: `xdebug.mode` is settable from any `.ini` a compromised process can write, and Xdebug can be told to
connect *out* to an attacker's host. The only safe way not to have that is not to build it.

### Debugging with an IDE

```sh
make install     # PHP dependencies into api/vendor, installed BY the container (run automatically by `make up`)
make debug-on    # arm Xdebug; `make debug-status` reports what the container actually sees
```

Then in the IDE: listen on port **9003**, ide key **`twes`**, and map **`/app` → `<this repo>/api`**.

Two things about this are worth knowing because both fail *silently* when wrong:

- **`api/vendor` must exist on the host**, which is what `make install` is for. It is a bind mount rather than a
  named volume on purpose: an IDE indexes the project directory, and a named volume lives in root-owned
  `/var/lib/docker/volumes/` where nothing will index it. Without a host `vendor/`, nothing resolves `Symfony\...`
  and a breakpoint in a vendor frame has no file to open — execution appears to step into nothing.
- **`host.docker.internal` does not exist on Linux** unless it is mapped to the host gateway, which
  `compose.override.yaml` does. Without that line Xdebug's callback target does not resolve, no connection is
  attempted, no error is raised, and breakpoints simply never hit.

Xdebug is armed **by trigger**, so even when on, only requests carrying `XDEBUG_TRIGGER` (or the `XDEBUG_SESSION`
cookie every IDE browser extension sets) are debugged. `xdebug.mode=debug` costs roughly 2-5x on *every* request
even with no IDE attached, which is why the default is `off` rather than something to remember to turn back off.

## Configuration — the dotenv cascade

`infra/.env` is **committed**, carries safe defaults, and every secret in it is deliberately **empty**;
`infra/.env.local` is **gitignored** and holds the real ones. Compose is given both. That is the Symfony
convention, and it is why there is no `.env.example` — a separate template is a Laravel/Node idiom and, here,
a second copy nothing reads.

`compose.override.yaml` is the **development** overlay and Docker Compose loads it automatically, which is why
the dev `make` targets pass no `-f` at all: passing `-f` switches Compose into explicit mode and the override
is then silently ignored. `compose.prod.yaml` is explicit for the opposite reason — it must never be picked up
by accident.

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
| **FrankenPHP worker mode — THREE preconditions, and this file is the one `infra/api/Caddyfile` says records them** (it did not, for a round: `grep -c worker infra/README.md` was 0 while the Caddyfile asserted *"`infra/README.md` records the two preconditions as owed"*) | (1) a Symfony 8 release of `runtime/frankenphp-symfony` — 1.0.0 declares `symfony/http-kernel: ^5.4 \|\| ^6.0 \|\| ^7.0` and this project is on 8.1, so Composer refuses it. (2) Proof that a tenant cannot stay bound to a connection a resident kernel reuses; `assertNoTenantPinnedOnTheConnection()` and `discardSessionState()` exist and are tested, but never under a real resident worker. (3) **The binding one, ruled 2026-08-05: the client portal (Wave 10) must have its own `random_bytes(32)` token.** `UuidV7` seeds its generator state once per PROCESS, so a worker serving many requests lets a seed recoverable from about two dozen observed identifiers span TENANTS — a certification round computed a later identifier exactly, across two generator instances with different clocks. **Not a matter of discipline, and it is TWO gates rather than one:** `scripts/gates/worker-mode-blocked.sh` sweeps every tracked file — scope is EXCLUSION-based, so a new dotenv layer, Dockerfile variant or tier is covered the moment it is tracked — and requires each `APP_RUNTIME` line to be one of three approved literals, ALL FOUR Caddyfile seams (derived from the Caddyfile's own `{$…}` placeholders) to be **empty**, and no active `worker` or `import` directive in a Caddyfile; it also refuses ANY `extra.runtime` key in ANY tracked `.json` — not just `class`, because `symfony/runtime`'s ComposerPlugin bakes every OTHER key into `vendor/autoload_runtime.php` as runtime constructor options (`autoload_template` replaces that file wholesale; `dotenv_path` + `dotenv_overload` override the container's own `DATABASE_URL` and reach the OWNER role), and not just a file NAMED `composer.json`, because `ENV COMPOSER=<file>` makes any file the root package. This sentence said `extra.runtime.class` for two commits after the rule widened, in a line rewritten by the later of them. `scripts/gates/compose-config.sh` covers the RENDERED compose environment — an overlay, an anchor, an `env_file:`, a value assembled from two files. Neither is sufficient alone. **This row credited `compose-config.sh` with all of it for two commits and `grep -c worker-mode-blocked infra/README.md` was 0**, which is the same defect the parenthesis at the head of this row records against an earlier version of itself. Both gates deleted in Wave 10 when the token lands, not before. |
| **PostgreSQL 18.4**, matching the pin | The API's money column is `NUMERIC(19,4)` and the tenancy guard is row-level security; both are server behaviour, so the server version is part of the contract. |
| **A non-superuser application role, without `BYPASSRLS`** | Row-level security does not apply to a superuser. `assertConnectionCannotBypassPolicies()` exists to fail loudly if this is got wrong. **It must run WHEN A CONNECTION IS ACQUIRED, and in CI — not once at boot**, which this row said for a round while the method's own docblock said the opposite: every question it asks is answered point-in-time, and a privileged role can change the answer under a connection that has already been certified, so "at boot" understates the exposure by the whole life of the process. Note it currently has **zero call sites** outside its own class — the wiring is owed in `build-waves.plan.md` § Wave 1. `scripts/gates/schema-tenancy.php` now refuses a runtime role that is `rolsuper`, `rolbypassrls` or `rolreplication`, which covers CI but not the running process. |
| **The runtime role must NOT OWN *any* table, and a deployment must set `DATABASE_URL_OWNER`** | `FORCE ROW LEVEL SECURITY` stops an owner *skipping* its policies; it does nothing about an owner *removing* them. A table owner can `ALTER TABLE … DISABLE ROW LEVEL SECURITY`, or add `CREATE POLICY … USING (true)`, in one statement. So migrations run as a separate, owning role and the application connects as a non-owner — **and that is now configuration rather than a deployment instruction**: `api/config/packages/doctrine.yaml` declares an `owner` connection fed by `DATABASE_URL_OWNER`, and `doctrine_migrations.yaml` pins migrations to it. **A deployment that overrides only `DATABASE_URL` does NOT fail — it silently migrates whatever the committed `.env` names, and exits 0.** This row asserted the opposite ("cannot migrate at all") for one commit, which was the third consecutive version of it to state a control nothing implemented; a reviewer proved the real behaviour by pointing `DATABASE_URL` at an unreachable host and watching `doctrine:migrations:status` report `twes_in` and succeed. There are three independent pointers at a database here — `DATABASE_URL`, `DATABASE_URL_OWNER` and the gate's `TWES_SCHEMA_DSN` — so CI can certify database #3 while migrations touch #2 and the application runs on #1, and nothing cross-checks that they agree. **A deployment must set `DATABASE_URL_OWNER` explicitly**; if genuine fail-closed behaviour is wanted, remove it from the tracked `.env` so `%env(resolve:...)%` throws. This row said "migrations run as a separate, owning role" for a fortnight while nothing implemented it, and `doctrine_migration_versions` in the dev database ended up owned by the runtime role; `scripts/gates/no-owner-connection-in-application.php` refuses any reference to the owner connection from `api/src/`, and `scripts/gates/schema-tenancy.php` refuses **any** table owned by the runtime role, tenant-owned or not, because a non-tenant table owned by that role proves the migration connection is wrong and the next tenant table will be too. Also required at the database level: the database itself must not be owned by the runtime role, or `public` belongs to `pg_database_owner` and the runtime role holds implicit `CREATE` — `REVOKE CREATE ON SCHEMA public FROM PUBLIC`, `USAGE` to the runtime role, `CREATE` to the owner. |
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
