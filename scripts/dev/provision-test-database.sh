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
# SEVEN roles, each earning its place by making one refusal branch testable:
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
#   twes_replicator
#                 LOGIN REPLICATION and nothing else. Round 5 recovered BOTH tenants' rows from a
#                 pg_basebackup taken with such a role while the isolation check certified it clean, because
#                 physical replication never touches row security. Exists so that refusal is proven live.
#   twes_probe_owner
#                 NOLOGIN, owns nothing normally. Granted to twes_owner WITH ADMIN OPTION so a test can hand
#                 it to the runtime role WITH INHERIT FALSE and own a table with it — the only way to prove
#                 that the OWNERSHIP axis uses membership semantics rather than inheritance ones.
#   twes_truncator
#                 plain, and granted to the runtime role **WITH INHERIT FALSE** — the PG16+ way to say "hold
#                 this deliberately, not by default". That grant is invisible to has_table_privilege and one
#                 SET ROLE away from the privilege, which is how round 5 erased every tenant's rows with
#                 current_user == session_user throughout. Exists so that gap stays closed.
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
REPLICATOR_ROLE="${TWES_TEST_DB_REPLICATOR_USER:-twes_replicator}"
REPLICATOR_PASSWORD="${TWES_TEST_DB_REPLICATOR_PASSWORD:-twes_replicator}"
TRUNCATOR_ROLE="${TWES_TEST_DB_TRUNCATOR_ROLE:-twes_truncator}"
PROBE_OWNER_ROLE="${TWES_TEST_DB_PROBE_OWNER_ROLE:-twes_probe_owner}"

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
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${REPLICATOR_ROLE}') THEN
        CREATE ROLE ${REPLICATOR_ROLE} LOGIN;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${TRUNCATOR_ROLE}') THEN
        CREATE ROLE ${TRUNCATOR_ROLE};
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${PROBE_OWNER_ROLE}') THEN
        CREATE ROLE ${PROBE_OWNER_ROLE};
    END IF;
END \$\$;

-- NOSUPERUSER NOBYPASSRLS NOCREATEROLE spelled out rather than left to the default: the whole suite is
-- vacuous if the runtime role acquires any of them, and a default is not a guarantee.
-- NOREPLICATION is spelled out with the rest, and it is the one whose omission mattered most: a role with
-- REPLICATION and no other privilege passes every SQL-level check and then hands over the whole cluster via
-- pg_basebackup. Round 5 demonstrated that the "re-running repairs a drifted role" promise above was false
-- for exactly this attribute.
ALTER ROLE ${RUNTIME_ROLE} WITH LOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION NOCREATEROLE NOCREATEDB
    PASSWORD '${RUNTIME_PASSWORD}';
ALTER ROLE ${OWNER_ROLE}   WITH LOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION NOCREATEROLE CREATEDB
    PASSWORD '${OWNER_PASSWORD}';
ALTER ROLE ${BYPASS_ROLE}  WITH LOGIN NOSUPERUSER BYPASSRLS NOREPLICATION NOCREATEROLE NOCREATEDB
    PASSWORD '${BYPASS_PASSWORD}';
ALTER ROLE ${MEMBER_ROLE}  WITH LOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION NOCREATEROLE NOCREATEDB
    PASSWORD '${MEMBER_PASSWORD}';
-- REPLICATION and nothing else. Note it is NOT BYPASSRLS and NOT superuser: that is the whole point, since
-- both of those were already detected and this one was not.
ALTER ROLE ${REPLICATOR_ROLE} WITH LOGIN NOSUPERUSER NOBYPASSRLS REPLICATION NOCREATEROLE NOCREATEDB
    PASSWORD '${REPLICATOR_PASSWORD}';
ALTER ROLE ${TRUNCATOR_ROLE} WITH NOLOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION;
ALTER ROLE ${PROBE_OWNER_ROLE} WITH NOLOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION;

-- The two grants that make the reachability tests possible, and ONLY on the probe role. Granting
-- either of these to ${RUNTIME_ROLE} is the misconfiguration the suite exists to detect.
GRANT ${BYPASS_ROLE} TO ${MEMBER_ROLE};
GRANT ${RUNTIME_ROLE} TO ${MEMBER_ROLE};

-- WITH INHERIT FALSE, deliberately: the runtime role does not hold the truncator's privileges by default,
-- so has_table_privilege() answers "no" while one SET ROLE reaches them. This is the shape the isolation
-- check must refuse, and it cannot be tested unless the fixture can express it.
GRANT ${TRUNCATOR_ROLE} TO ${RUNTIME_ROLE} WITH INHERIT FALSE;

-- ADMIN OPTION so the OWNER connection can grant and revoke this role inside a test. Needed because the
-- ownership-reachability axis is otherwise untestable: the existing test connects AS the table's owner, and a
-- role always satisfies pg_has_role() on itself under every mode, so MEMBER -> USAGE survives as an
-- equivalent mutant. What has to be proven is a role REACHING an owner it does not inherit, and only a
-- superuser can set up the delegation that lets a test arrange that.
GRANT ${PROBE_OWNER_ROLE} TO ${OWNER_ROLE} WITH ADMIN OPTION, INHERIT FALSE;

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
-- REVOKE FROM PUBLIC FIRST. PostgreSQL grants CONNECT and TEMPORARY to PUBLIC on every new database, so
-- the GRANT below was inert and the comment claiming "CONNECT but not CREATE" was only half true. Harmless
-- while there is one shared database; in the per-tenant-database mode TenantIsolationStrategy advertises,
-- PUBLIC's default CONNECT makes every tenant's database reachable by every tenant's role with row-level
-- security not involved at all.
REVOKE CONNECT, TEMPORARY ON DATABASE ${DB} FROM PUBLIC;
GRANT CONNECT ON DATABASE ${DB} TO ${RUNTIME_ROLE}, ${BYPASS_ROLE}, ${MEMBER_ROLE}, ${REPLICATOR_ROLE};
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT  USAGE  ON SCHEMA public TO ${RUNTIME_ROLE}, ${BYPASS_ROLE}, ${MEMBER_ROLE}, ${REPLICATOR_ROLE};
GRANT  CREATE ON SCHEMA public TO ${OWNER_ROLE};
-- The probe owner needs CREATE on the schema to be allowed to OWN an object in it, even though it never
-- creates one itself — PostgreSQL checks the incoming owner's schema privileges on ALTER TABLE ... OWNER TO.
GRANT  CREATE ON SCHEMA public TO ${PROBE_OWNER_ROLE};

-- Table privileges for tables the owner has not created yet. DML but deliberately NOT TRUNCATE:
-- TRUNCATE is never subject to row security at any privilege level, so a runtime role holding it can
-- erase every tenant's rows while every policy remains in place.
ALTER DEFAULT PRIVILEGES FOR ROLE ${OWNER_ROLE} IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${RUNTIME_ROLE};
ALTER DEFAULT PRIVILEGES FOR ROLE ${OWNER_ROLE} IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${BYPASS_ROLE}, ${MEMBER_ROLE}, ${REPLICATOR_ROLE};
ALTER DEFAULT PRIVILEGES FOR ROLE ${OWNER_ROLE} IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO ${RUNTIME_ROLE};
SQL

echo "provision-test-database: OK — ${DB} owned by ${OWNER_ROLE}; ${RUNTIME_ROLE} is a restricted non-owner."
