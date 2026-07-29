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
| `docker compose config` clean, `bash -n` on every script | The infra tier's equivalent of a test suite — see `CLAUDE.md` § "Quality gate". |
| **PostgreSQL 18.4**, matching the pin | The API's money column is `NUMERIC(19,4)` and the tenancy guard is row-level security; both are server behaviour, so the server version is part of the contract. |
| **A non-superuser application role, without `BYPASSRLS`** | Row-level security does not apply to a superuser. `PostgresRowLevelSecurityIsolation::assertConnectionCannotBypassPolicies()` exists to fail loudly if this is got wrong, and it must run at boot. |
| **The runtime role must NOT OWN the tenant-owned tables** | `FORCE ROW LEVEL SECURITY` stops an owner *skipping* its policies; it does nothing about an owner *removing* them. A table owner can `ALTER TABLE … DISABLE ROW LEVEL SECURITY`, or add `CREATE POLICY … USING (true)`, in one statement. So migrations run as a separate, owning role and the application connects as a non-owner. |
| **`REVOKE TRUNCATE` from the runtime role** | `TRUNCATE` is **never** subject to row-level security at any privilege level — it is gated only by the TRUNCATE privilege. A test pins this behaviour so nobody mistakes RLS for a defence against it. |
| **The connection must carry no pre-set `twes.tenant_id`** | A DSN can pin a session GUC with `options='-c twes.tenant_id=…'`, needing no privilege — exactly what a `DATABASE_URL` carries. Because `bind()` writes transaction-locally, that value is restored on COMMIT and the unbound path would then read that tenant's rows. Assert `current_setting('twes.tenant_id', true)` is NULL or empty at connection acquisition. |
| **Every tenant-owned table: `ENABLE` + `FORCE` + a policy with `USING` and `WITH CHECK`, and composite keys** | Use `PostgresRowLevelSecurityIsolation::policySqlFor()` rather than hand-writing the policy. FK and uniqueness checks run with row security BYPASSED, so `PRIMARY KEY (company_id, id)` with foreign keys and unique constraints on **both** columns is required — a single-column FK lets one tenant delete another's rows. |
| No product name, hostname, logo or e-mail address hardcoded | All branding is configuration from day one, so a later public deployment is a config change and not a code change. `CLAUDE.md` § "Licensing invariants", item 9. |
| CI mirroring the quality gate tier by tier | Every job commented with why it exists and what breaks without it — house convention. |
