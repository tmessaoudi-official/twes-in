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

	-- ----------------------------------------------------------------------------------------------------------
	-- DATABASE AND SCHEMA OWNERSHIP.
	-- ----------------------------------------------------------------------------------------------------------
	ALTER DATABASE :"owner_role" OWNER TO :"owner_role";
	SQL

# `ALTER DATABASE` cannot take the database name from a psql variable in the same statement it renames, so the
# ownership change runs separately with the real name interpolated by the shell -- safe here because $DB comes from
# the image's own POSTGRES_DB, not from a request.
psql --username "$POSTGRES_USER" --dbname postgres --set ON_ERROR_STOP=1 --no-psqlrc \
    -c "ALTER DATABASE \"${DB}\" OWNER TO \"${OWNER_ROLE}\""

psql --username "$POSTGRES_USER" --dbname "$DB" \
    --set ON_ERROR_STOP=1 --no-psqlrc \
    -v runtime_role="$RUNTIME_ROLE" \
    -v owner_role="$OWNER_ROLE" \
    <<-'SQL'
	-- The public schema belongs to the owner, and the runtime role may USE it but never CREATE in it. A runtime
	-- role that can create a table can create one WITHOUT row-level security and copy tenant data into it.
	ALTER SCHEMA public OWNER TO :"owner_role";
	REVOKE CREATE ON SCHEMA public FROM PUBLIC;
	GRANT USAGE ON SCHEMA public TO :"runtime_role";

	-- Connect, and nothing else at database level. TEMPORARY is NOT granted: a temporary table is session-private
	-- but it is still a place to materialise tenant rows outside any policy, and nothing here needs one.
	REVOKE ALL ON DATABASE :"owner_role" FROM PUBLIC;

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
	SQL

log "OK — ${DB} owned by ${OWNER_ROLE}; ${RUNTIME_ROLE} is a restricted non-owner with DML but no TRUNCATE."
log "     the owning role is NOT granted to the runtime role, which is what stops SET ROLE reaching it."
