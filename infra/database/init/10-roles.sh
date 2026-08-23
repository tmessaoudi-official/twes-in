#!/bin/bash
#
# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later

# ==============================================================================================================
# PostgreSQL first-boot provisioning: the OWNER / RUNTIME role split, and the privileges each one gets.
#
# The `postgres` image runs every executable in `/docker-entrypoint-initdb.d/` ONCE, on first boot of an empty data
# directory. That is exactly the right hook for this: role topology is a property of the cluster, not of a
# migration, and it must exist before the first migration runs.
#
# WHY THIS FILE IS NOT OPTIONAL, and why it is not `provision-test-database.sh`. That script builds TWELVE roles,
# because a *test* fixture has to be able to express every dangerous shape the suite proves impossible — a
# `BYPASSRLS` role, a `REPLICATION` role, a role granted `WITH INHERIT FALSE`. Production must be able to express
# NONE of them. So this is a deliberately different, deliberately smaller topology, and the two must not be merged:
# a shared script would either weaken the tests or ship attack roles to production.
#
# THE SPLIT ITSELF is the control. `FORCE ROW LEVEL SECURITY` stops a table's owner SKIPPING its policies; it does
# not stop the owner REMOVING them, because `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` is an owner's privilege.
# So the role the application connects as must never own a tenant table, and must never be able to `SET ROLE` to
# the role that does. That was a real P0 in this project (CLAUDE.md § Gotchas, round 4), found asserted in prose
# and enforced nowhere.
# ==============================================================================================================

set -euo pipefail

log() { printf '[twes-init] %s\n' "$1" >&2; }

# The runtime password is the one secret this script needs and it is NOT defaulted. A default would be a published
# credential for the role that reads every tenant's data, and "it was only the dev default" is how those reach
# production. Absent means the container refuses to initialise, loudly, on first boot.
if [ "${TWES_DB_RUNTIME_PASSWORD:-}" = "" ]; then
    log 'FATAL: TWES_DB_RUNTIME_PASSWORD is not set.'
    log '  The runtime role reads tenant data. This script will not invent a password for it, because a default'
    log '  here is a published credential — set it in your .env or your secret manager.'
    exit 1
fi

if [ "${TWES_DB_OWNER_PASSWORD:-}" = "" ]; then
    log 'FATAL: TWES_DB_OWNER_PASSWORD is not set.'
    log '  The owning role can DISABLE ROW LEVEL SECURITY on every tenant table. It needs a real password more'
    log '  than the runtime role does, not less.'
    exit 1
fi

RUNTIME_ROLE="${TWES_DB_RUNTIME_ROLE:-twes}"
OWNER_ROLE="${TWES_DB_OWNER_ROLE:-twes_owner}"
DB="${POSTGRES_DB:-twes}"

log "provisioning ${DB}: owner=${OWNER_ROLE} runtime=${RUNTIME_ROLE}"

# `--set ON_ERROR_STOP=1` so a failed statement aborts initialisation instead of leaving a half-provisioned cluster
# that looks healthy. `-v ... ` passes values without interpolating them into SQL text.
psql --username "$POSTGRES_USER" --dbname "$DB" \
    --set ON_ERROR_STOP=1 \
    --no-psqlrc \
    -v runtime_role="$RUNTIME_ROLE" \
    -v owner_role="$OWNER_ROLE" \
    -v runtime_password="$TWES_DB_RUNTIME_PASSWORD" \
    -v owner_password="$TWES_DB_OWNER_PASSWORD" \
    <<-'SQL'
	-- ----------------------------------------------------------------------------------------------------------
	-- THE OWNING ROLE. Creates and alters the schema; owns every table; runs migrations.
	--
	-- NOCREATEROLE and NOCREATEDB deliberately: owning the schema does not require the ability to mint new roles
	-- or databases, and a migration credential that can create roles can create itself a bypass.
	-- ----------------------------------------------------------------------------------------------------------
	CREATE ROLE :"owner_role" LOGIN PASSWORD :'owner_password'
	    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;

	-- ----------------------------------------------------------------------------------------------------------
	-- THE RUNTIME ROLE. What the application connects as, and the only credential a serving container holds.
	--
	-- Every attribute here is a refusal, and each one is checked by `scripts/gates/schema-tenancy.php`:
	--   NOSUPERUSER    — a superuser is never subject to row-level security at all.
	--   NOBYPASSRLS    — the attribute that exists solely to skip policies.
	--   NOREPLICATION  — `pg_basebackup` reads the heap directly, with row security never involved. This is the
	--                    one bypass NO attack can observe, because it does not traverse the query layer, which is
	--                    why the gate is the only thing that can see it.
	--   NOINHERIT      — it inherits nothing from any role it might later be granted.
	-- ----------------------------------------------------------------------------------------------------------
	CREATE ROLE :"runtime_role" LOGIN PASSWORD :'runtime_password'
	    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;

	-- **THE OWNER IS NEVER GRANTED TO THE RUNTIME ROLE.** This absence is the whole control, so it is stated
	-- rather than left implicit:
	--     GRANT :"owner_role" TO :"runtime_role";   -- NEVER. One SET ROLE away from DISABLE ROW LEVEL SECURITY.
	-- `provision-test-database.sh` carries the same comment for the same reason, and the gate reproduces it as a
	-- reachability check rather than a string comparison, because `SET ROLE` is authorised by MEMBERSHIP.

	SQL

# DATABASE OWNERSHIP. `ALTER DATABASE` cannot take the database name from a psql variable in the same statement it
# renames, so the ownership change runs separately with the real name interpolated by the shell -- safe here because
# $DB comes from the image's own POSTGRES_DB, not from a request.
#
# The block above used to end with `ALTER DATABASE :"owner_role" OWNER TO :"owner_role";`, which passed the ROLE name
# where the DATABASE name belongs and so ran `ALTER DATABASE "twes_owner"` against a database of that name that has
# never existed. [Verified: `ERROR: database "twes_owner" does not exist` in the container log, on the statement
# quoted verbatim.] `ON_ERROR_STOP=1` then did its job and aborted -- meaning EVERY statement after it was skipped:
# this ownership change, the schema ownership, the REVOKE, the runtime GRANT and both ALTER DEFAULT PRIVILEGES.
# [Verified on the resulting cluster: `pg_namespace.nspowner` for `public` was still `pg_database_owner`,
# `pg_database.datdba` for `twes` was still `postgres`, and `pg_default_acl` held 0 rows.] The visible symptom was
# five minutes away from the cause -- the migration failing with `permission denied for schema public`.
psql --username "$POSTGRES_USER" --dbname postgres --set ON_ERROR_STOP=1 --no-psqlrc \
    -c "ALTER DATABASE \"${DB}\" OWNER TO \"${OWNER_ROLE}\""

psql --username "$POSTGRES_USER" --dbname "$DB" \
    --set ON_ERROR_STOP=1 --no-psqlrc \
    -v runtime_role="$RUNTIME_ROLE" \
    -v owner_role="$OWNER_ROLE" \
    -v db_name="$DB" \
    <<-'SQL'
	-- The public schema belongs to the owner, and the runtime role may USE it but never CREATE in it. A runtime
	-- role that can create a table can create one WITHOUT row-level security and copy tenant data into it.
	ALTER SCHEMA public OWNER TO :"owner_role";
	REVOKE CREATE ON SCHEMA public FROM PUBLIC;
	GRANT USAGE ON SCHEMA public TO :"runtime_role";

	-- Connect, and nothing else at database level. TEMPORARY is NOT granted: a temporary table is session-private
	-- but it is still a place to materialise tenant rows outside any policy, and nothing here needs one.
	-- `:"db_name"`, NOT `:"owner_role"`. This said `owner_role` until 2026-08-04 -- the same role-name-for-database-
	-- name substitution as the deleted `ALTER DATABASE` above, and it would have aborted initialisation here instead,
	-- skipping the two ALTER DEFAULT PRIVILEGES below and leaving the runtime role with access to nothing.
	REVOKE ALL ON DATABASE :"db_name" FROM PUBLIC;
	GRANT CONNECT ON DATABASE :"db_name" TO :"runtime_role";
	GRANT CONNECT ON DATABASE :"db_name" TO :"owner_role";

	-- ----------------------------------------------------------------------------------------------------------
	-- DEFAULT PRIVILEGES — the part that is easy to get wrong, and that this project got wrong.
	--
	-- These apply to tables the owner has not created YET, which is what makes the migration's output usable
	-- without a second grant step after every deploy. **The migration itself issues no GRANT**, and the runtime
	-- role's access comes entirely from here — a defect found on 2026-08-01 when a freshly migrated database gave
	-- the application no access to any table at all, and recorded in `build-waves.plan.md` as an open item.
	--
	-- `ALTER DEFAULT PRIVILEGES` is PER-DATABASE, so this must run inside :"owner_role"'s database and nowhere
	-- else. That per-database scoping is exactly why the test fixture's grants never reached a probe database.
	--
	-- DML but deliberately NOT TRUNCATE: TRUNCATE is never subject to row security at any privilege level, so a
	-- runtime role holding it can erase every tenant's rows while every policy remains in place.
	-- ----------------------------------------------------------------------------------------------------------
	ALTER DEFAULT PRIVILEGES FOR ROLE :"owner_role" IN SCHEMA public
	    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"runtime_role";
	ALTER DEFAULT PRIVILEGES FOR ROLE :"owner_role" IN SCHEMA public
	    GRANT USAGE, SELECT ON SEQUENCES TO :"runtime_role";

	-- ----------------------------------------------------------------------------------------------------------
	-- LARGE-OBJECT WRITERS, REVOKED FROM PUBLIC. Round 3's R3S-4, and the half without which the other half is
	-- unshippable.
	--
	-- `pg_largeobject` CANNOT CARRY ROW-LEVEL SECURITY at any privilege level, so a large object is data that
	-- escapes tenancy entirely — the same class as TRUNCATE, by a different route. Every one of these six functions
	-- ships with a NULL `proacl`, which means PUBLIC holds EXECUTE, which means the restricted runtime role holds
	-- it. [Verified on 18.4: `lo_creat`, `lo_create`, `lo_from_bytea`, `lo_put` and `lowrite` all report a NULL acl
	-- and `has_function_privilege('twes', …)` true on an untouched cluster.]
	--
	-- `assertConnectionCannotCreateLargeObjects()` has existed since Wave 0 and is composed into the
	-- acquisition-time guard behind `TWES_ASSERT_REVOKED_CAPABILITIES`. Round 3 found that flag never reaches a
	-- container — so the assertion ran NOWHERE — and, crucially, that plumbing it alone would have made the stack
	-- refuse every connection, because this revocation did not exist. Both halves land together or neither works.
	--
	-- The list is TAKEN FROM the checker's own `LARGE_OBJECT_WRITERS` constant rather than written independently:
	-- round 15 found `lo_creat` missing from both the constant and the documented REVOKE block, which is the
	-- two-copies-of-one-list defect this project records against every other enumeration in it. If the constant
	-- grows, this block is what has to grow with it, and `compose-config.sh` asserts the two agree.
	--
	-- THAT LAST CLAUSE WAS FALSE UNTIL 2026-08-23 (round 4, R4S-2): nothing anywhere compared them, so a
	-- seventh entry in the constant left every gate green and would then have refused EVERY connection
	-- acquisition in api, worker and scheduler -- a total outage caused by editing a security list. The
	-- check now exists, compares BY FUNCTION NAME (this block names lo_import twice, once per signature),
	-- runs in BOTH directions, and sits ABOVE that gate's `docker compose` probe so it never skips.
	REVOKE EXECUTE ON FUNCTION lo_creat(integer) FROM PUBLIC;
	REVOKE EXECUTE ON FUNCTION lo_create(oid) FROM PUBLIC;
	REVOKE EXECUTE ON FUNCTION lo_from_bytea(oid, bytea) FROM PUBLIC;
	REVOKE EXECUTE ON FUNCTION lo_import(text) FROM PUBLIC;
	REVOKE EXECUTE ON FUNCTION lo_import(text, oid) FROM PUBLIC;
	REVOKE EXECUTE ON FUNCTION lo_put(oid, bigint, bytea) FROM PUBLIC;
	REVOKE EXECUTE ON FUNCTION lowrite(integer, bytea) FROM PUBLIC;
	SQL

# --------------------------------------------------------------------------------------------------------------
# VERIFY THE END STATE. Not belt-and-braces — this closes a failure mode that made the bug above cost an evening.
#
# `ON_ERROR_STOP=1` aborts this script, but the postgres entrypoint has ALREADY INITIALISED the data directory by the
# time it runs us. So a failure here leaves a populated `PGDATA`, and on the next start the entrypoint prints
# `PostgreSQL Database directory appears to contain a database; Skipping initialization` and the cluster comes up
# HEALTHY AND HALF-PROVISIONED — permanently, because this hook never runs again. [Verified: both were in one
# container's log, `sourcing /docker-entrypoint-initdb.d/10-roles.sh` and, 8 lines later after the restart,
# `Skipping initialization`; the cluster then passed its healthcheck with `public` unowned and `pg_default_acl` empty.]
# That is this project's recurring "a control that silently did not run" shape (CLAUDE.md § Gotchas) in its worst
# form, because the silence is durable rather than per-run.
#
# So assert the four properties the rest of the stack depends on, by READING THE CATALOGUE rather than trusting that
# the statements above returned. A mismatch prints what to do about it, because the fix is not obvious: the data
# directory must be DESTROYED (`make destroy`) for this hook to run again.
verify_failed=''
check() {
    actual="$(psql --username "$POSTGRES_USER" --dbname "$DB" --no-psqlrc -tAc "$2")"
    [ "$actual" = "$3" ] || verify_failed="${verify_failed}
  - $1: expected '$3', got '$actual'"
}

check "public schema owner" \
    "SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = 'public'" "$OWNER_ROLE"
check "database owner" \
    "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = current_database()" "$OWNER_ROLE"
check "runtime role has USAGE but not CREATE on public" \
    "SELECT has_schema_privilege('$RUNTIME_ROLE', 'public', 'USAGE') || ',' || has_schema_privilege('$RUNTIME_ROLE', 'public', 'CREATE')" \
    "true,false"
# Two rows: one for TABLES, one for SEQUENCES. Counted rather than compared as text, because the ACL's rendering is
# a PostgreSQL implementation detail while its ABSENCE is the defect that broke the migration.
check "default privileges recorded for the owner" \
    "SELECT count(*) FROM pg_default_acl d JOIN pg_roles r ON r.oid = d.defaclrole WHERE r.rolname = '$OWNER_ROLE'" "2"

if [ -n "$verify_failed" ]; then
    log 'FATAL: provisioning did not reach the expected end state.'
    log "  The following checks failed:${verify_failed}"
    log '  The data directory is already initialised, so THIS HOOK WILL NOT RUN AGAIN and the next start will'
    log '  report the cluster healthy while it is half-provisioned. Destroy the volume and start over:'
    log '      make destroy && make up'
    exit 1
fi

log "OK — ${DB} owned by ${OWNER_ROLE}; ${RUNTIME_ROLE} is a restricted non-owner with DML but no TRUNCATE."
log "     the owning role is NOT granted to the runtime role, which is what stops SET ROLE reaching it."
log '     end state verified against the catalogue: schema and database ownership, USAGE-not-CREATE, default privileges.'
