#!/bin/sh
#
# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# ==============================================================================================================
# API container entrypoint.
#
# `/bin/sh` and not bash: the runtime image is Alpine and adding bash to a production image for a 100-line script
# is a dependency that serves no request. POSIX sh is enough for everything below.
#
# WHAT THIS DOES AND, MORE IMPORTANTLY, WHAT IT REFUSES TO DO. It does not run migrations. That is not an omission
# and the reason is the whole design:
#
#   - Migrations must run as the OWNING role, never as the runtime role. `doctrine_migrations.yaml` pins them to the
#     `owner` connection precisely because a table owned by the runtime role is one
#     `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` from every tenant's data — a real P0 in this project's history
#     (CLAUDE.md § Gotchas, 2026-08-01). An entrypoint that migrated would have to hold the owner credential in
#     every replica, which is exactly the privilege concentration the role split exists to prevent.
#   - With more than one replica, N containers starting together would run migrations concurrently. Doctrine takes
#     no lock across processes, so that is a race whose outcome is a half-applied schema.
#
# So migration is a DEPLOY STEP with its own short-lived container and its own credential (`make migrate`, or the
# `migrate` service in compose.yaml). This entrypoint's job is to refuse to serve until the schema it needs is
# actually there, which is a different and safer thing.
# ==============================================================================================================

set -eu

log() {
    # Structured, to stderr, matching the Caddy log format so one pipeline can read both.
    printf '{"level":"%s","logger":"entrypoint","msg":"%s","ts":"%s"}\n' \
        "$1" "$2" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >&2
}

fail() {
    log error "$1"
    exit 1
}

# --------------------------------------------------------------------------------------------------------------
# 1. REQUIRED CONFIGURATION. Fail fast and by NAME, rather than serving 500s later.
# --------------------------------------------------------------------------------------------------------------
# `APP_SECRET` is checked because the image ships a build-time placeholder for cache warming. If that placeholder
# ever reached production it would be a KNOWN secret in a public repository — so the check is not tidiness, it is
# the difference between a secret and a constant.
[ "${APP_SECRET:-}" = "" ] && fail "APP_SECRET is not set. The image ships a build-time placeholder that must never be used at run time."
[ "${APP_SECRET:-}" = "build-time-placeholder-not-a-secret" ] && fail "APP_SECRET is still the build-time placeholder. Generate one: openssl rand -hex 32"
[ "${DATABASE_URL:-}" = "" ] && fail "DATABASE_URL is not set."

# The runtime container must NOT be given the owner credential. If it is, the role split is decorative: any code
# path in the application could reach the role that owns the tenant tables. Refusing here is what makes the split
# real, and it is cheap to check.
if [ "${DATABASE_URL_OWNER:-}" != "" ] && [ "${TWES_ALLOW_OWNER_CREDENTIAL:-}" != "1" ]; then
    fail "DATABASE_URL_OWNER is set in a RUNTIME container. The owning role must only ever be held by the migration step — a runtime process that can reach it can DISABLE ROW LEVEL SECURITY on every tenant table. Unset it, or set TWES_ALLOW_OWNER_CREDENTIAL=1 if this container really is the migrator."
fi

# --------------------------------------------------------------------------------------------------------------
# 2. IN A NON-PRODUCTION ENVIRONMENT, LET THE FRAMEWORK CREATE ITS OWN CACHE DIRECTORY.
# --------------------------------------------------------------------------------------------------------------
# The image warms `var/cache/prod` at build time and then makes it READ-ONLY (`chmod -R 555`), which is deliberate
# hardening rather than tidiness: with write permission on that DIRECTORY a compromised PHP process could unlink and
# replace `Twes_KernelProdContainer.php`, and the compiled container is code — that is a persistence vector, not a
# denial of service. So in production it stays exactly as built and this block does nothing.
#
# In any other environment the framework must compile its own container, into `var/cache/dev` or `var/cache/test`,
# and it cannot create that subdirectory inside a 555 parent. The dev overlay mounts a named volume over `/app/var`,
# and Docker SEEDS a fresh volume from the image — permissions included — so the read-only mode propagates into the
# volume and the stack dies on the first command:
#
#   In KernelTrait.php line 293:  Unable to create the "cache" directory (/app/var/cache/dev).
#
# [Verified: that is the verbatim failure from the `migrate` service before this block existed.] Restoring owner-write
# is legitimate for this process rather than a privilege grab — `twes` already OWNS the directory, so this only undoes
# a build-time mode, and it is scoped by `APP_ENV` so production cannot reach it.
if [ "${APP_ENV:-prod}" != "prod" ] && [ ! -w /app/var/cache ]; then
    chmod u+w /app/var/cache
    log info "APP_ENV=${APP_ENV:-prod}: made /app/var/cache writable so the framework can compile its own container"
fi

# --------------------------------------------------------------------------------------------------------------
# 3. WAIT FOR THE DATABASE, bounded.
# --------------------------------------------------------------------------------------------------------------
# Bounded on purpose. An unbounded wait makes a misconfigured DSN look like a slow database, and the container sits
# "starting" forever instead of crash-looping visibly — which is how a broken deploy stays undiagnosed. Compose
# also declares `depends_on: service_healthy`, so this is the second layer, for the case where the database goes
# away after it was once healthy.
DB_WAIT_TIMEOUT="${TWES_DB_WAIT_TIMEOUT:-60}"
waited=0

while ! php -r '
    $url = getenv("DATABASE_URL");
    $parts = parse_url($url);
    if (false === $parts || !isset($parts["host"])) { exit(2); }
    parse_str($parts["query"] ?? "", $q);
    $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s", $parts["host"], $parts["port"] ?? 5432, ltrim($parts["path"] ?? "", "/"));
    try { new PDO($dsn, rawurldecode($parts["user"] ?? ""), rawurldecode($parts["pass"] ?? ""), [PDO::ATTR_TIMEOUT => 2]); }
    catch (Throwable $e) { exit(1); }
' 2>/dev/null; do
    waited=$((waited + 2))

    if [ "$waited" -ge "$DB_WAIT_TIMEOUT" ]; then
        fail "the database did not accept a connection within ${DB_WAIT_TIMEOUT}s. This is a HARD failure rather than an endless wait, so a wrong DATABASE_URL is visible as a crash-loop instead of a container stuck in 'starting'."
    fi

    log info "waiting for the database (${waited}s/${DB_WAIT_TIMEOUT}s)"
    sleep 2
done

log info "database reachable"

# --------------------------------------------------------------------------------------------------------------
# 4. REFUSE TO SERVE AN UNMIGRATED SCHEMA.
# --------------------------------------------------------------------------------------------------------------
# `doctrine:migrations:up-to-date` exits non-zero when a migration is pending. Serving in that state is worse than
# not starting: the API would answer some requests and fail others depending on which table a code path touches,
# and a partially working billing API is harder to diagnose than one that is plainly down.
#
# Skippable by `TWES_SKIP_MIGRATION_CHECK=1`, which exists for one legitimate case — a deliberate rolling deploy
# where the new image is expected to run against the old schema for a few minutes. That is a real pattern and
# refusing it outright would force someone to fork this file.
#
# **`--conn=default` IS REQUIRED, AND WITHOUT IT THIS GATE CAN NEVER PASS IN A SERVING CONTAINER.** `doctrine_migrations
# .yaml` pins the bundle to the `owner` connection — correctly, because APPLYING a migration must happen as the owning
# role — and that binding applies to every command the bundle provides, including this read-only one. But section 1
# above REFUSES to start a runtime container that holds the owner credential, so the `owner` connection here resolves
# to whatever `DATABASE_URL_OWNER` falls back to: `api/.env`'s local development default, `127.0.0.1:5432`. The gate
# therefore tried to reach a database inside its own container and failed for a reason unrelated to the schema:
#
#   SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: Connection refused
#
# ...reported to the operator as `the database schema is NOT up to date`, which is actively misleading — the schema was
# fully migrated. [Verified: the `migrate` service logged `Successfully migrated to version:
# Twes\Migrations\Version20260802090000` while `api`, `worker` and `scheduler` all crash-looped on that message; with
# `--conn=default` the same command returns `[OK] Up-to-date! No migrations to execute.` and exit 0.]
#
# Reading as the RUNTIME role is not a workaround, it is the correct privilege level: this gate only needs to know
# whether `doctrine_migration_versions` lists every migration on disk, and SELECT on that table is exactly what the
# runtime role holds through the default privileges the init script grants. Checking is a read; applying is a write.
if [ "${TWES_SKIP_MIGRATION_CHECK:-}" != "1" ]; then
    if ! php bin/console doctrine:migrations:up-to-date --conn=default --no-interaction >/dev/null 2>&1; then
        fail "the database schema is NOT up to date. Run the migration step first (\`make migrate\`, or the \`migrate\` service), which connects as the OWNING role. Set TWES_SKIP_MIGRATION_CHECK=1 only for a deliberate rolling deploy against the previous schema."
    fi

    log info "schema up to date"
fi

# --------------------------------------------------------------------------------------------------------------
# 5. HAND OVER.
# --------------------------------------------------------------------------------------------------------------
# `exec` so the server becomes PID 1 and receives SIGTERM directly. Without it the shell is PID 1, signals are not
# forwarded, and every deploy waits for the orchestrator's kill timeout before the container dies — turning a
# graceful shutdown into a 10-second stall per replica and dropping in-flight requests.
log info "starting $*"
exec "$@"
