#!/usr/bin/env bash
#
# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Provisions the DEVELOPMENT database with the ownership topology production must have.
#
# WHY THIS EXISTS, and it is a security fix rather than a convenience. `provision-test-database.sh` gets
# `twes_in_test` right and NOTHING got `twes_in` right: the dev database was created the obvious way, owned by
# the RUNTIME role. In PostgreSQL 15+ the `public` schema belongs to `pg_database_owner`, so owning the
# database hands the runtime role `CREATE` on `public` through an IMPLICIT membership — with no grant anywhere
# for an audit to find. One `CREATE TABLE` later it OWNS a tenant-owned table, and an owner can
# `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` in a single statement. `FORCE ROW LEVEL SECURITY` stops an owner
# SKIPPING policies, not REMOVING them.
#
# That was corrected BY HAND on 2026-08-01, which is why this script exists: a hand-fixed cluster means every
# fresh container reproduces the wrong shape, and `scripts/gates/schema-tenancy.php` only catches the
# consequence afterwards, on an already-migrated database. This is what makes the shape right to begin with.
#
# DELIBERATELY NOT THE TEST SCRIPT'S TWELVE ROLES. Two roles, and no more. `provision-test-database.sh` builds
# `BYPASSRLS`, `REPLICATION` and half a dozen membership-shaped fixtures so that the isolation suite's REFUSAL
# branches can be proven against real connections — every one of them exists to make a dangerous shape
# testable. A role that bypasses row security entirely has no business on the cluster a developer's browser
# talks to, so none of them is created here.
#
#   ${TWES_DEV_DB_USER:-twes}          the runtime role. LOGIN, and nothing else: no SUPERUSER, no BYPASSRLS,
#                                      no CREATEROLE, no CREATEDB, and NOT a member of the owner.
#   ${TWES_DEV_DB_OWNER_USER:-twes_owner}
#                                      owns the database and the tables; migrations run as this role, which is
#                                      what `doctrine_migrations.connection: owner` in `doctrine.yaml` selects.
#
# IT ISSUES NO CONNECTION FLAGS. libpq's own `PGHOST`/`PGPORT`/`PGUSER`/`PGPASSWORD` carry the connection, so
# the ordinary path is `sudo -u postgres bash scripts/dev/provision-dev-database.sh` over the local socket and
# `api/tests/Integration/Tenancy/DevDatabaseProvisioningTest.php` drives the same script over TCP. One script,
# both paths, and no `--host`/`--user` list to keep in step with a test harness.
#
# RE-RUNNABLE, and that is a requirement rather than a nicety: a developer runs it before migrating and again
# afterwards. It never overwrites an existing role's password — see the role block for why.
#
# IT CORRECTS, RATHER THAN ONLY CREATES, AND THAT IS THE WHOLE VALUE OF RUNNING IT ON AN EXISTING CLUSTER. Three
# things are stated unconditionally so a database or role already in the wrong shape is repaired instead of
# tolerated: the DATABASE's owner, the runtime role's ATTRIBUTES (a pre-existing role was previously skipped
# outright, so a leftover SUPERUSER or BYPASSRLS `twes` was accepted in silence — and either one means row-level
# security never applies to the application's own connection), and the OWNER OF EVERY RELATION the runtime role
# holds (`REASSIGN OWNED BY`, because correcting the database and the schema left `doctrine_migration_versions`
# owned by `twes` — the very shape § Gotchas 2026-08-01 records as a P0). The password is the one exception, and
# the asymmetry is deliberate: a credential is the developer's choice, an attribute that defeats tenant
# isolation is not.
set -euo pipefail

DB="${TWES_DEV_DB_NAME:-twes_in}"
RUNTIME_ROLE="${TWES_DEV_DB_USER:-twes}"
RUNTIME_PASSWORD="${TWES_DEV_DB_PASSWORD:-twes}"
OWNER_ROLE="${TWES_DEV_DB_OWNER_USER:-twes_owner}"
OWNER_PASSWORD="${TWES_DEV_DB_OWNER_PASSWORD:-twes_owner}"

# The defaults match `api/.env`'s `DATABASE_URL` and `DATABASE_URL_OWNER` on purpose — they are throwaway local
# credentials that are already committed, and a developer running this with no environment set should end up
# with a cluster the checked-in dotenv can reach. Overriding any of them is what a real deployment does, and a
# real deployment does not run this script at all.

psql_do() {
    psql --no-psqlrc --set ON_ERROR_STOP=1 "$@"
}

# EVERY PARAMETERISED STATEMENT GOES THROUGH STDIN, NEVER `-c`, and that is not a style choice: **`psql -c` sends
# its argument straight to the server without psql's own variable interpolation**, so `:'DB'` arrives at
# PostgreSQL literally and comes back as `syntax error at or near ":"`. The first version of this script used
# `-c` for the probes and every one of them failed that way. Interpolation happens in psql's lexer, which only
# runs on stdin and on `-f`.
#
# That matters beyond convenience: it is what lets psql do the QUOTING. `:"VAR"` is interpolated as an
# identifier and `:'VAR'` as a literal, so a database name or password containing a quote character cannot end
# the statement early -- which shell interpolation into a `-c` string would allow.
psql_scalar() {
    psql --no-psqlrc --set ON_ERROR_STOP=1 -tA "$@"
}

# ------------------------------------------------------------------------------------------------------------
# 1. FAIL CLOSED ON A CLUSTER THAT CANNOT ANSWER, keeping psql's own error text.
#
# `set -e` would abort with no explanation, and a refusal reading only "probe failed" sends a reader hunting
# for the wrong cause -- CLAUDE.md § Gotchas records exactly that costing an afternoon when a
# `password authentication failed` was really two PostgreSQL clusters sharing port 5432. So the probe is
# captured and quoted verbatim.
# ------------------------------------------------------------------------------------------------------------
if ! server="$(psql_do -tAc "select 'PostgreSQL ' || current_setting('server_version') || ' on port ' || current_setting('port')" 2>&1)"; then
    printf '\n!! CANNOT REACH THE CLUSTER, so nothing was provisioned.\n\n' >&2
    printf '   psql said: %s\n\n' "$server" >&2
    printf '   This container runs clusters 16 and 18 BOTH configured on port 5432, so a restart can leave the\n' >&2
    printf '   wrong one holding it. Start the right one:\n\n' >&2
    printf '     pg_ctlcluster 16 main stop && pg_ctlcluster 18 main start\n\n' >&2
    printf '   Otherwise check PGHOST/PGPORT/PGUSER/PGPASSWORD, or run under `sudo -u postgres`.\n' >&2
    exit 1
fi

# ------------------------------------------------------------------------------------------------------------
# 2. REFUSE A DATABASE HOLDING SOMEBODY ELSE'S OBJECTS -- BEFORE anything is changed.
#
# `provision-test-database.sh` refuses a database that holds ANY relation, because a test database is a
# throwaway that its own suite recreates. **That guard cannot transfer here** and copying it would have made
# this script single-use: a dev database legitimately holds migrated tables, and re-running after a migration
# is the normal case.
#
# So the question is not WHETHER there are objects but WHOSE. A relation owned by a role that is neither of
# ours means this is a shared or foreign database, and the `REVOKE CREATE ON SCHEMA public FROM PUBLIC` below
# would break whatever else was using it -- a change with no obvious symptom and no obvious cause.
#
# It runs FIRST, so a refusal leaves the cluster exactly as it was, including creating no roles.
#
# The strictness is deliberate and has one known false positive worth naming: an extension that installs
# objects into `public` (PostGIS, say) is owned by the installing superuser and will trip this. That is the
# right trade for a script whose whole job is ownership, and the message says which relations to look at.
# ------------------------------------------------------------------------------------------------------------
if db_exists="$(psql_scalar --set DB="$DB" 2>&1 <<'PROBE'
SELECT count(*) FROM pg_database WHERE datname = :'DB';
PROBE
)"; then
    if [[ "$db_exists" != "0" ]]; then
        if ! strangers="$(psql_do -tA --dbname "$DB" \
            --set RUNTIME_ROLE="$RUNTIME_ROLE" \
            --set OWNER_ROLE="$OWNER_ROLE" <<'PROBE' 2>&1
SELECT string_agg(n.nspname || '.' || c.relname || ' (owned by ' || pg_get_userbyid(c.relowner) || ')', ', '
                  ORDER BY n.nspname, c.relname)
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
  AND n.nspname NOT LIKE 'pg_toast%'
  AND n.nspname NOT LIKE 'pg_temp%'
  AND c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S')
  AND pg_get_userbyid(c.relowner) NOT IN (:'RUNTIME_ROLE', :'OWNER_ROLE');
PROBE
        )"; then
            printf '\n!! COULD NOT INSPECT DATABASE "%s", so it cannot be shown to be ours. Nothing was changed.\n\n' "$DB" >&2
            printf '   psql said: %s\n' "$strangers" >&2
            exit 1
        fi

        if [[ -n "${strangers//[[:space:]]/}" ]]; then
            printf '\n!! REFUSING TO PROVISION: database "%s" holds relations owned by neither "%s" nor "%s".\n\n' \
                "$DB" "$RUNTIME_ROLE" "$OWNER_ROLE" >&2
            printf '   %s\n\n' "$strangers" >&2
            printf '   This looks like a shared or foreign database rather than a development one. Provisioning it\n' >&2
            printf '   would REVOKE CREATE ON SCHEMA public FROM PUBLIC and change the database owner, which\n' >&2
            printf '   breaks whatever else was using it -- with no obvious symptom and no obvious cause.\n\n' >&2
            printf '   If it really is a throwaway, drop it and re-run, or point TWES_DEV_DB_NAME elsewhere.\n' >&2
            exit 1
        fi
    fi
else
    printf '\n!! COULD NOT DETERMINE WHETHER DATABASE "%s" EXISTS. Nothing was changed.\n\n' "$DB" >&2
    printf '   psql said: %s\n' "$db_exists" >&2
    exit 1
fi

# ------------------------------------------------------------------------------------------------------------
# 3. THE TWO ROLES, created only when ABSENT.
#
# **An existing role's password is never overwritten**, and that is the guard that makes this safe to re-run
# beside `provision-test-database.sh`. Both scripts know the same two role names, so an ALTER here would
# silently replace a password the other script -- or the developer -- had chosen, and the failure would surface
# later as an authentication error against a credential that "has always worked".
#
# `NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS` are written out rather than left to
# PostgreSQL's defaults. They ARE the defaults; stating them is what makes the intent checkable by a reader and
# means a future edit has to delete a word rather than merely forget one. `NOINHERIT` is NOT set: neither role
# is a member of anything, so inheritance has nothing to inherit, and setting it would suggest otherwise.
#
# The names are interpolated by psql as `:"VAR"` (an identifier) and the passwords as `:'VAR'` (a literal), so
# psql does the quoting and a value containing a quote character cannot end the statement early.
# ------------------------------------------------------------------------------------------------------------
created_roles=()
found_roles=()

# PARALLEL ARRAYS rather than colon-joined strings. The first version packed name and password into one
# `name:password` string and split it back out, which is unreadable and silently wrong for a password containing
# a colon -- and a password is exactly the kind of value that contains anything.
role_names=("$RUNTIME_ROLE" "$OWNER_ROLE")
role_passwords=("$RUNTIME_PASSWORD" "$OWNER_PASSWORD")

for index in "${!role_names[@]}"; do
    role="${role_names[$index]}"
    password="${role_passwords[$index]}"

    present="$(psql_scalar --set ROLE="$role" <<'PROBE'
SELECT count(*) FROM pg_roles WHERE rolname = :'ROLE';
PROBE
)"

    if [[ "$present" != "0" ]]; then
        found_roles+=("$role")

        # THE ATTRIBUTES ARE CORRECTED ON A ROLE THAT ALREADY EXISTS, AND THE PASSWORD IS NOT. The first version
        # `continue`d here, so a `twes` left over as SUPERUSER or BYPASSRLS from an experiment was accepted in
        # silence -- and that is not cosmetic: either attribute means row-level security NEVER APPLIES to the
        # application's own connection, so every tenant's documents are readable through the ordinary code path
        # while `schema-tenancy.php` still reports clean, because that gate reads the policies rather than asking
        # who can ignore them. REPLICATION is the same breach by another road: `pg_basebackup` reads the whole
        # cluster with row security never involved. [Reproduced: a pre-created runtime role kept all five
        # attributes across a full provisioning run, exit 0.]
        #
        # The ASYMMETRY with the password is the point rather than an inconsistency. A password is the
        # developer's own choice and overwriting it would clobber the test fixture's credential -- the reason
        # this script never touches one. An attribute that defeats tenant isolation is not a choice this script
        # can defer to. So: credentials left alone, attributes stated unconditionally, exactly as
        # `ALTER DATABASE ... OWNER TO` below is issued unconditionally so a wrongly-created database is
        # CORRECTED rather than tolerated.
        #
        # `ALTER ROLE` rather than probing first and altering conditionally: the statement is idempotent, so a
        # probe would add a branch whose false arm is unreachable and untestable.
        psql_do --set ROLE="$role" > /dev/null <<'SQL'
ALTER ROLE :"ROLE" NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
SQL

        continue
    fi

    # A HEREDOC, not a `-c` string. The `-c` form needs `:"ROLE"` and `:'PASSWORD'` in one shell word, which means
    # nesting both quote characters inside a single-quoted string -- the first attempt got `bash -n: unexpected EOF
    # while looking for matching quote`, caught by the write-time hook. A quoted heredoc delimiter passes the body
    # to psql untouched, so psql does all the quoting and bash does none.
    psql_do --set ROLE="$role" --set PASSWORD="$password" > /dev/null <<'SQL'
CREATE ROLE :"ROLE" LOGIN PASSWORD :'PASSWORD'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
SQL
    created_roles+=("$role")
done

# ------------------------------------------------------------------------------------------------------------
# 4. THE DATABASE, owned by the OWNER role.
#
# `CREATE DATABASE` cannot run inside a transaction block, which is why it is its own `-c` invocation rather
# than part of the block below. `ALTER DATABASE ... OWNER TO` is issued unconditionally so an existing database
# created the wrong way is CORRECTED rather than left alone -- that is the whole point of the script, and the
# dev cluster this repository was built on was in exactly that state.
# ------------------------------------------------------------------------------------------------------------
existing="$(psql_scalar --set DB="$DB" <<'PROBE'
SELECT count(*) FROM pg_database WHERE datname = :'DB';
PROBE
)"

if [[ "$existing" == "0" ]]; then
    psql_do --set DB="$DB" --set OWNER_ROLE="$OWNER_ROLE" > /dev/null <<'SQL'
CREATE DATABASE :"DB" OWNER :"OWNER_ROLE";
SQL
fi

psql_do --dbname "$DB" \
    --set DB="$DB" \
    --set RUNTIME_ROLE="$RUNTIME_ROLE" \
    --set OWNER_ROLE="$OWNER_ROLE" <<'SQL' > /dev/null
ALTER DATABASE :"DB" OWNER TO :"OWNER_ROLE";

-- THE SCHEMA'S OWNER, SET EXPLICITLY. From PostgreSQL 15 a fresh `public` belongs to `pg_database_owner`, so
-- the ALTER above is normally enough -- but a database restored from a pre-15 dump carries a `public` owned by
-- a CONCRETE role, and if that role is the runtime one it keeps CREATE regardless of who owns the database.
-- Stating it costs one idempotent statement and closes a shape that is otherwise invisible.
ALTER SCHEMA public OWNER TO pg_database_owner;

-- CONNECT but never CREATE. Revoked from PUBLIC first, because PostgreSQL grants CONNECT and TEMPORARY to
-- PUBLIC on every new database -- so a GRANT without the REVOKE is inert and the comment claiming the runtime
-- role is restricted would be only half true.
REVOKE CONNECT, TEMPORARY ON DATABASE :"DB" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"DB" TO :"RUNTIME_ROLE";

-- NO `GRANT TEMPORARY`. The test database needs it for the column-fidelity suite's temporary table, and that
-- grant is not free: a temporary table outlives the transaction-scoped tenant binding under which its rows
-- were selected, carries no row-level security of its own, and is readable under whatever tenant is bound to
-- that connection NEXT -- certification round 11 read one tenant's rows while bound to another through one.
-- `provision-test-database.sh` says "in production this grant should simply be absent"; a development database
-- is the closest thing to production a developer touches, so it is absent here.

-- EVERY RELATION THE RUNTIME ROLE OWNS IS HANDED TO THE OWNER. Correcting the DATABASE and the SCHEMA is not
-- enough, and this was the gap: `doctrine_migration_versions` in this repository's own development database was
-- owned by `twes` (§ Gotchas 2026-08-01), one `ALTER TABLE ... DISABLE ROW LEVEL SECURITY` from every tenant's
-- data. `FORCE ROW LEVEL SECURITY` does not help -- it stops an owner SKIPPING a policy, not REMOVING one.
--
-- Nothing else sees it. `schema-tenancy.php` refuses a schema whose tenant tables the runtime role owns, so a
-- FRESH migration is caught; this script exists for a database that is ALREADY in the wrong shape, and the
-- foreign-object refusal above does not fire either, because the runtime role is one of this script's own two
-- rather than a stranger.
--
-- `REASSIGN OWNED BY` rather than a loop over `pg_class` issuing `ALTER TABLE ... OWNER TO`: it covers every
-- object KIND at once -- tables, sequences, views, functions, types -- so a kind nobody thought of is handled
-- rather than left behind, which is the enumeration defect this repository has recorded against three separate
-- gates. It is per-database and this block runs inside the target database, which is exactly the scope wanted.
--
-- It is a no-op when the runtime role owns nothing, so it is issued unconditionally for the same reason
-- `ALTER DATABASE ... OWNER TO` is: a probe would add a branch whose false arm cannot be reached.
REASSIGN OWNED BY :"RUNTIME_ROLE" TO :"OWNER_ROLE";

-- THE CORE OF THE SCRIPT. `public` is world-creatable by default on a pre-15 dump and creatable by the
-- database owner always, so both the PUBLIC grant and any explicit one to the runtime role have to go.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM :"RUNTIME_ROLE";
GRANT  USAGE  ON SCHEMA public TO :"RUNTIME_ROLE";
GRANT  CREATE ON SCHEMA public TO :"OWNER_ROLE";

-- Privileges for tables the owner has NOT CREATED YET, which is what a migration produces. DML and
-- deliberately NOT TRUNCATE: TRUNCATE is never subject to row security at any privilege level, so a runtime
-- role holding it can erase every tenant's rows while every policy remains in place. `BehaviouralIsolationTest`
-- attacks that grant on the test database; this is what stops it existing on the development one.
ALTER DEFAULT PRIVILEGES FOR ROLE :"OWNER_ROLE" IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"RUNTIME_ROLE";
-- Sequences: USAGE and SELECT, never UPDATE. Nothing in this application calls `setval` -- a document number
-- comes from a counter ROW precisely because a PostgreSQL SEQUENCE is not transactional -- so UPDATE would be a
-- privilege with no caller.
ALTER DEFAULT PRIVILEGES FOR ROLE :"OWNER_ROLE" IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO :"RUNTIME_ROLE";
SQL

# ------------------------------------------------------------------------------------------------------------
# 5. SAY WHAT HAPPENED. Which roles were created and which were found already present, because "created" and
#    "left alone with whatever password it had" are different outcomes and only one of them means the
#    credentials in `api/.env` will work.
# ------------------------------------------------------------------------------------------------------------
printf 'provision-dev-database: OK — %s owned by %s; %s can connect and read but cannot CREATE, TRUNCATE or own.\n' \
    "$DB" "$OWNER_ROLE" "$RUNTIME_ROLE"
printf 'provision-dev-database: on %s.\n' "$server"

if [[ "${#created_roles[@]}" -gt 0 ]]; then
    printf 'provision-dev-database: roles CREATED: %s\n' "${created_roles[*]}"
fi

if [[ "${#found_roles[@]}" -gt 0 ]]; then
    printf 'provision-dev-database: roles already present, passwords LEFT UNCHANGED: %s\n' "${found_roles[*]}"
    printf 'provision-dev-database:   (if a connection fails, the password is the one that role already had —\n'
    printf 'provision-dev-database:    this script never overwrites one, so it cannot clobber the test fixture.)\n'
fi
