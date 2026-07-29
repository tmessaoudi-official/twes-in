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
| No product name, hostname, logo or e-mail address hardcoded | All branding is configuration from day one, so a later public deployment is a config change and not a code change. `CLAUDE.md` § "Licensing invariants", item 9. |
| CI mirroring the quality gate tier by tier | Every job commented with why it exists and what breaks without it — house convention. |
