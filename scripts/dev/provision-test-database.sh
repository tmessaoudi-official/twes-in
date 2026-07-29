#!/usr/bin/env bash
#
# This file is part of twes-in.
#
# (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
#
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Provisions the PostgreSQL roles the integration suite needs.
#
# WHY THIS EXISTS, and why it is not three createuser lines in CLAUDE.md any more: the tenancy proof is
# only worth something if it runs against the topology production uses. A single role that owns the
# tenant-owned tables can DISABLE ROW LEVEL SECURITY or TRUNCATE them in one statement — FORCE stops an
# owner *skipping* policies, not *removing* them — so a suite provisioned that way proves isolation
# against a connection that could step around it entirely. It also leaves the checks that detect the
# dangerous shapes with no way to be exercised: creating a BYPASSRLS role needs a privilege the runtime
# role must never hold, so the refusal branches stay untested for as long as there is one role.
#
# Four roles, each earning its place by making one refusal branch testable:
#
#   twes          the runtime role. Restricted: no SUPERUSER, no BYPASSRLS, no CREATEROLE, member of
#                 nothing privileged, and NOT the owner of the tenant-owned tables. This is the role the
#                 application connects as, and the one every isolation assertion is made against.
#   twes_owner    owns the tenant-owned tables and their policies; migrations run as this role. `twes`
#                 is deliberately NOT a member of it — that grant is the ordinary convenience wiring
#                 that reopens the whole bypass, so the suite proves the un-granted shape.
#   twes_bypass   has BYPASSRLS and nothing else. Exists so the refusal branch can be proven live
#                 against a real privileged connection instead of only through a pure predicate.
#   twes_member   plain attributes of its own, but a member of BOTH twes_bypass and twes. Exists to
#                 prove the two reachability defects: privileges reached by SET ROLE rather than held
#                 directly, and the same reached from session_user while current_user looks harmless.
#
# Run as a superuser. Idempotent — safe to re-run.
set -euo pipefail

DB="${TWES_TEST_DB_NAME:-twes_in_test}"

RUNTIME_ROLE="${TWES_TEST_DB_USER:-twes}"
RUNTIME_PASSWORD="${TWES_TEST_DB_PASSWORD:-twes}"
OWNER_ROLE="${TWES_TEST_DB_OWNER_USER:-twes_owner}"
OWNER_PASSWORD="${TWES_TEST_DB_OWNER_PASSWORD:-twes_owner}"
BYPASS_ROLE="${TWES_TEST_DB_BYPASS_USER:-twes_bypass}"
BYPASS_PASSWORD="${TWES_TEST_DB_BYPASS_PASSWORD:-twes_bypass}"
MEMBER_ROLE="${TWES_TEST_DB_MEMBER_USER:-twes_member}"
MEMBER_PASSWORD="${TWES_TEST_DB_MEMBER_PASSWORD:-twes_member}"

psql --no-psqlrc --set ON_ERROR_STOP=1 <<SQL
-- CREATE ROLE has no IF NOT EXISTS, so each one is guarded. ALTER after CREATE rather than instead of
-- it, so that re-running also repairs a role whose attributes drifted.
DO \$\$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${RUNTIME_ROLE}') THEN
        CREATE ROLE ${RUNTIME_ROLE} LOGIN;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${OWNER_ROLE}') THEN
        CREATE ROLE ${OWNER_ROLE} LOGIN;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${BYPASS_ROLE}') THEN
        CREATE ROLE ${BYPASS_ROLE} LOGIN;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${MEMBER_ROLE}') THEN
        CREATE ROLE ${MEMBER_ROLE} LOGIN;
    END IF;
END \$\$;

-- NOSUPERUSER NOBYPASSRLS NOCREATEROLE spelled out rather than left to the default: the whole suite is
-- vacuous if the runtime role acquires any of them, and a default is not a guarantee.
ALTER ROLE ${RUNTIME_ROLE} WITH LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB
    PASSWORD '${RUNTIME_PASSWORD}';
ALTER ROLE ${OWNER_ROLE}   WITH LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE CREATEDB
    PASSWORD '${OWNER_PASSWORD}';
ALTER ROLE ${BYPASS_ROLE}  WITH LOGIN NOSUPERUSER BYPASSRLS   NOCREATEROLE NOCREATEDB
    PASSWORD '${BYPASS_PASSWORD}';
ALTER ROLE ${MEMBER_ROLE}  WITH LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB
    PASSWORD '${MEMBER_PASSWORD}';

-- The two grants that make the reachability tests possible, and ONLY on the probe role. Granting
-- either of these to ${RUNTIME_ROLE} is the misconfiguration the suite exists to detect.
GRANT ${BYPASS_ROLE} TO ${MEMBER_ROLE};
GRANT ${RUNTIME_ROLE} TO ${MEMBER_ROLE};

-- Explicitly NOT granted, stated so a future reader does not "fix" it:
--   GRANT ${OWNER_ROLE} TO ${RUNTIME_ROLE};   -- would let the runtime role SET ROLE to the table owner
SQL

# The database is owned by the OWNER role, so the owner can create the tenant-owned tables in it while
# the runtime role cannot create tables at all.
if ! psql --no-psqlrc -tAc "SELECT 1 FROM pg_database WHERE datname = '${DB}'" | grep -q 1; then
    createdb --owner="${OWNER_ROLE}" "${DB}"
fi

psql --no-psqlrc --set ON_ERROR_STOP=1 --dbname "${DB}" <<SQL
ALTER DATABASE ${DB} OWNER TO ${OWNER_ROLE};

-- CONNECT but not CREATE: the runtime role gets no DDL on the database, which is what makes "the
-- application cannot own a table" true by construction rather than by convention.
GRANT CONNECT ON DATABASE ${DB} TO ${RUNTIME_ROLE}, ${BYPASS_ROLE}, ${MEMBER_ROLE};
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT  USAGE  ON SCHEMA public TO ${RUNTIME_ROLE}, ${BYPASS_ROLE}, ${MEMBER_ROLE};
GRANT  CREATE ON SCHEMA public TO ${OWNER_ROLE};

-- Table privileges for tables the owner has not created yet. DML but deliberately NOT TRUNCATE:
-- TRUNCATE is never subject to row security at any privilege level, so a runtime role holding it can
-- erase every tenant's rows while every policy remains in place.
ALTER DEFAULT PRIVILEGES FOR ROLE ${OWNER_ROLE} IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${RUNTIME_ROLE};
ALTER DEFAULT PRIVILEGES FOR ROLE ${OWNER_ROLE} IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${BYPASS_ROLE}, ${MEMBER_ROLE};
ALTER DEFAULT PRIVILEGES FOR ROLE ${OWNER_ROLE} IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO ${RUNTIME_ROLE};
SQL

echo "provision-test-database: OK — ${DB} owned by ${OWNER_ROLE}; ${RUNTIME_ROLE} is a restricted non-owner."
