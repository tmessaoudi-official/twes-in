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
# Each role earns its place by making ONE refusal branch testable. No count is written here: this list
# has grown at four separate certification rounds and a number in a comment beside the thing it counts
# is the first thing to drift. `grep -c '^#   twes' ` this block, or read pg_roles.
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
#   twes_unsettable
#                 plain, NOLOGIN, and granted to the runtime role **WITH INHERIT FALSE, SET FALSE** — a
#                 membership the runtime role holds but CANNOT SET ROLE to. The eighth role, added at round 14
#                 because the seven above could not express the shape: every one of them is either inherited or
#                 settable, so pg_has_role(..., 'MEMBER') and pg_has_role(..., 'SET') agreed on all of them and
#                 reverting roleCanBeAssumedSql()'s 'SET' to 'MEMBER' left the whole suite green. 'MEMBER' is
#                 true here and 'SET' is false, which is the only input that tells the two apart — so this role
#                 is what makes round 12's fix load-bearing instead of merely present.
#   twesMixedCase
#                 the NINTH role, and the only one whose name is not all-lowercase. It exists because
#                 `current_user::regrole` DOWNCASES: PostgreSQL folds an unquoted identifier, so the cast raises
#                 `role "twesmixedcase" does not exist` and every object check that used it raised instead of
#                 answering — a total outage traced to entirely the wrong cause. Round 13 replaced the cast with
#                 a `pg_roles` lookup and round 14 found the fix REVERTIBLE with the whole suite green, because
#                 all eight roles above are lowercase and cannot express the shape. LOGIN, because the check
#                 reads `current_user` and therefore has to be REACHED as this role rather than named.
#
#                 It matters because the predicate is NEGATED: under 'MEMBER', a function owned by a role the
#                 connection is a member of but cannot become was excluded as "no escalation", when that is
#                 precisely the case where the function is the ONLY route to that role's rights.
#
# Run as a superuser, and ONLY against a throwaway test cluster — see THE THROWAWAY-CLUSTER CONSENT GUARD
# below for why that is now checked rather than assumed.
#
# Idempotent for everything it CREATES: every role is guarded by an existence check and every grant and
# privilege is re-asserted to the same value, so a re-run repairs drift and changes nothing else. It is NOT
# idempotent with respect to operator state in one place: it overwrites the superuser's own password, which
# no re-run can restore. That single statement is therefore gated on explicit consent, and the closing
# summary names the role whose password it set.
set -euo pipefail

DB="${TWES_TEST_DB_NAME:-twes_in_test}"

RUNTIME_ROLE="${TWES_TEST_DB_USER:-twes}"
RUNTIME_PASSWORD="${TWES_TEST_DB_PASSWORD:-twes}"
# The SUPERUSER's own password. Round 16 found that making the superuser credential REQUIRED (round 15) broke the
# documented path: nothing in this repo ever set a password on `postgres`, TCP auth here is scram-sha-256, and so
# `api/phpunit.xml`'s postgres/postgres default could not authenticate against a cluster this script produced. A
# fresh checkout got 22 integration failures whose message pointed at the two-cluster trap -- the wrong cause.
#
# Set here because this script already runs AS a superuser and is explicitly a LOCAL TEST fixture; a real
# deployment neither runs it nor wants a password-authenticated superuser. MANY tests need one to build
# privileged fixtures and four of them are the only evidence a security fix is load-bearing, so the credential
# has to work or the suite fails closed -- which it now does, loudly, rather than skipping.
#
# NO COUNT IS WRITTEN HERE. The number was "nine" for one commit and was wrong when written; it is derived with
#   grep -c 'self::superuserConnection()' api/tests/Integration/Tenancy/TenantIsolationTest.php
# which is the same derivation CLAUDE.md § "Quality gate" gives, for the same reason: this figure has been
# written as "one", "nine" and "eleven" in three successive rounds and was stale every time. It was introduced
# here by the very commit that DELETED it from api/phpunit.xml because counts drift.
SUPERUSER_ROLE="${TWES_TEST_DB_SUPERUSER:-postgres}"
# Consent is captured BEFORE the default is applied, and it is the ONLY thing that distinguishes "the operator
# named this password" from "the operator got postgres/postgres because they said nothing". Read once into a
# variable that every later path consults, because CLAUDE.md § Gotchas records a guard implemented on one write
# path and not the other.
if [[ -n "${TWES_TEST_DB_SUPERUSER_PASSWORD:+set}" ]]; then
    SUPERUSER_PASSWORD_CONSENTED=1
else
    SUPERUSER_PASSWORD_CONSENTED=0
fi
SUPERUSER_PASSWORD="${TWES_TEST_DB_SUPERUSER_PASSWORD:-postgres}"
OWNER_ROLE="${TWES_TEST_DB_OWNER_USER:-twes_owner}"
OWNER_PASSWORD="${TWES_TEST_DB_OWNER_PASSWORD:-twes_owner}"
BYPASS_ROLE="${TWES_TEST_DB_BYPASS_USER:-twes_bypass}"
BYPASS_PASSWORD="${TWES_TEST_DB_BYPASS_PASSWORD:-twes_bypass}"
MEMBER_ROLE="${TWES_TEST_DB_MEMBER_USER:-twes_member}"
MEMBER_PASSWORD="${TWES_TEST_DB_MEMBER_PASSWORD:-twes_member}"
REPLICATOR_ROLE="${TWES_TEST_DB_REPLICATOR_USER:-twes_replicator}"
REPLICATOR_PASSWORD="${TWES_TEST_DB_REPLICATOR_PASSWORD:-twes_replicator}"
TRUNCATOR_ROLE="${TWES_TEST_DB_TRUNCATOR_ROLE:-twes_truncator}"
UNSETTABLE_ROLE="${TWES_TEST_DB_UNSETTABLE_ROLE:-twes_unsettable}"
# Quoted everywhere it appears in SQL below, deliberately: unquoted, PostgreSQL folds it and the
# role this fixture creates would not be the role the test connects as.
MIXED_CASE_ROLE="${TWES_TEST_DB_MIXED_CASE_ROLE:-twesMixedCase}"
MIXED_CASE_PASSWORD="${TWES_TEST_DB_MIXED_CASE_PASSWORD:-twesMixedCase}"
PROBE_OWNER_ROLE="${TWES_TEST_DB_PROBE_OWNER_ROLE:-twes_probe_owner}"

# ── THE TWO-CLUSTER GUARD, drafted 2026-07-30 ──────────────────────────────────────────────────
# WHY: this script provisions whichever cluster happens to own port 5432, and says nothing about
# which one that was. On 2026-07-30 that cost the tenancy proof entirely: this container ships
# PostgreSQL 16 and 18 BOTH configured on 5432, the 16 cluster won the port after a restart, every
# integration connection got `password authentication failed for user "twes"`, and the suite
# reported OK with exit 0. The roles existed — on the other cluster.
#
# A guard, not a fix: it cannot choose for the operator, but it can refuse to be silent. The failure
# it prevents is provisioning cluster A while the suite connects to cluster B.
if command -v pg_lsclusters >/dev/null 2>&1; then
  configured_on_5432="$(pg_lsclusters --no-header 2>/dev/null | awk '$3 == 5432 { print $1 "/" $2 " (" $4 ")" }')"
  count="$(printf '%s\n' "$configured_on_5432" | grep -c . || true)"

  if [[ "$count" -gt 1 ]]; then
    printf '\n!! MORE THAN ONE POSTGRESQL CLUSTER IS CONFIGURED ON PORT 5432:\n' >&2
    printf '%s\n' "$configured_on_5432" | sed 's/^/     /' >&2
    printf '   Only one can bind it. Roles created now land in whichever is ONLINE, and the\n' >&2
    printf '   integration suite will connect to whichever owns the port when IT runs -- not\n' >&2
    printf '   necessarily the same one. This exact mismatch made the tenancy proof skip while\n' >&2
    printf '   reporting OK (CLAUDE.md, Gotchas 2026-07-30).\n' >&2
    printf '   Stop the one you do not want, e.g.: pg_ctlcluster 16 main stop\n\n' >&2
  fi
fi

# And say plainly WHERE the roles were created, so a later mismatch is diagnosable from this output
# alone rather than by rediscovering the trap.
printf 'provision-test-database: provisioning into %s\n' \
  "$(psql --no-psqlrc -tAc "select 'PostgreSQL ' || current_setting('server_version') || ' on port ' || current_setting('port') || ', database ' || current_database()" 2>/dev/null || echo 'an unknown server')"

# ── THE THROWAWAY-CLUSTER CONSENT GUARD ────────────────────────────────────────────────────────
# WHY: one statement below sets the SUPERUSER's own password, and it is the only statement in this script that
# mutates a pre-existing, cluster-global role. Every other ALTER ROLE targets a role this script created under
# an existence check, and every grant is scoped to ${DB}. That one is different in three ways that matter:
#
#   (a) it converts a password-less superuser into one authenticable OVER THE WIRE with a dictionary default,
#       so `PGPASSWORD=postgres psql ...` starts succeeding. That this container only listens on loopback is
#       the CLUSTER's property, not this script's, and infra/ is not written yet -- so the script cannot
#       inherit the containment as an assumption.
#   (b) pg_authid is a SHARED catalogue. The change escapes ${DB} and applies to every database on the
#       cluster, including twes_in. Nothing else here leaves ${DB}.
#   (c) it is the one thing a re-run cannot restore. An operator's password is overwritten and gone.
#
# The two-cluster block above is a WARNING and cannot answer "is this a throwaway cluster?" -- it does not even
# fire when one cluster is configured on 5432 while another listens elsewhere. So the precondition is checked
# directly, and a failure REFUSES. It does not skip the statement and carry on: CLAUDE.md § Gotchas records
# four separate instances of a control that silently did not run, one of them this script's own warning.
#
# The precondition is deliberately two-part, because either half alone is satisfiable by accident:
#   1. ${DB} is absent, or exists and holds ZERO relations -- a database with tables in it is somebody's
#      state, and this fixture's own suite leaves none behind, so an empty ${DB} is the normal case.
#   2. TWES_TEST_DB_SUPERUSER_PASSWORD was named EXPLICITLY. A default cannot express consent.
refusals=()

if [[ "$SUPERUSER_PASSWORD_CONSENTED" -ne 1 ]]; then
    refusals+=("TWES_TEST_DB_SUPERUSER_PASSWORD is not set to a non-empty value, so nothing here consents to overwriting the password of the cluster-global superuser role \"${SUPERUSER_ROLE}\".")
fi

# Fail CLOSED on a probe that cannot answer, and KEEP psql's own error text when it cannot. `set -e` does not
# fire inside a command substitution feeding an assignment, so the status is not trusted -- but neither is the
# output discarded on failure: a refusal reading only "probe failed" sends a reader hunting for the wrong cause,
# which is the mistake this script's own two-cluster message was written to avoid. stderr is folded into the
# capture so the refusal below can quote it verbatim.
db_probe="$(psql --no-psqlrc -tA --set ON_ERROR_STOP=1 --set DB="$DB" <<'PROBE' 2>&1
SELECT count(*) FROM pg_database WHERE datname = :'DB';
PROBE
)" && db_probe_ok=1 || db_probe_ok=0

if [[ "$db_probe_ok" -eq 1 && "$db_probe" == '1' ]]; then
    relation_probe="$(psql --no-psqlrc -tA --set ON_ERROR_STOP=1 --dbname "$DB" <<'PROBE' 2>&1
SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
   AND n.nspname NOT LIKE 'pg_temp%' AND n.nspname NOT LIKE 'pg_toast_temp%';
PROBE
)" && relation_probe_ok=1 || relation_probe_ok=0

    if [[ "$relation_probe_ok" -ne 1 || ! "$relation_probe" =~ ^[0-9]+$ ]]; then
        refusals+=("could not count the relations in database \"${DB}\", so it cannot be shown to be a throwaway. psql said: ${relation_probe}")
    elif [[ "$relation_probe" -ne 0 ]]; then
        refusals+=("database \"${DB}\" already holds ${relation_probe} relation(s), so it is not a throwaway test database. This fixture's own suite drops everything it creates, so a non-empty ${DB} is somebody else's state.")
    fi
elif [[ "$db_probe_ok" -ne 1 || "$db_probe" != '0' ]]; then
    refusals+=("could not determine whether database \"${DB}\" exists, so this cluster cannot be shown to be a throwaway. psql said: ${db_probe}")
fi

if [[ "${#refusals[@]}" -gt 0 ]]; then
    printf '\n!! REFUSING TO PROVISION: this cannot be shown to be a throwaway test cluster.\n\n' >&2
    printf '%s\n' "${refusals[@]}" | sed 's/^/   - /' >&2
    printf '\n   Nothing was changed. This script sets the password of the cluster-global superuser role\n' >&2
    printf '   "%s" in the SHARED pg_authid catalogue -- that reaches every database on this cluster,\n' "${SUPERUSER_ROLE}" >&2
    printf '   including twes_in, and no re-run can restore the password it overwrites.\n\n' >&2
    printf '   To consent, name the password explicitly:\n' >&2
    printf '     sudo -u postgres env TWES_TEST_DB_SUPERUSER_PASSWORD=<password> \\\n' >&2
    printf '       bash scripts/dev/provision-test-database.sh\n\n' >&2
    printf '   api/phpunit.xml expects postgres/postgres by default, so on a throwaway cluster that is\n' >&2
    printf '     sudo -u postgres env TWES_TEST_DB_SUPERUSER_PASSWORD=postgres \\\n' >&2
    printf '       bash scripts/dev/provision-test-database.sh\n\n' >&2
    printf '   If %s is NOT empty and you meant to reprovision it, drop it first, or point this script\n' "${DB}" >&2
    printf '   elsewhere with TWES_TEST_DB_NAME.\n\n' >&2
    exit 1
fi

# Every PASSWORD, and the superuser's role NAME, are passed as psql variables and written below as :'VAR'
# (a literal) or :"VAR" (an identifier), so psql does the quoting and a value containing a quote character
# cannot terminate the statement it sits in. Shell-interpolating a password into '...' means a single
# apostrophe in it changes the statement rather than the password.
#
# WHAT IS STILL SHELL-INTERPOLATED, and why: the role NAMES of the roles this script creates. They appear in
# the DO block below, and psql does NOT substitute variables inside a dollar-quoted body -- verified, not
# assumed. Making those escape-safe means replacing the DO block with `SELECT format('CREATE ROLE %I ...')
# ... \gexec`, which is a rewrite of the block rather than a change to it, so it is left here deliberately and
# named rather than described as impossible. Note the consequence is bounded: those names come from the
# operator's own environment on a fixture they are already running as a superuser, so this is a robustness
# defect -- a name with a quote in it produces a wrong statement -- not a privilege boundary.
psql --no-psqlrc --set ON_ERROR_STOP=1 \
    --set SUPERUSER_ROLE="$SUPERUSER_ROLE" \
    --set SUPERUSER_PASSWORD="$SUPERUSER_PASSWORD" \
    --set RUNTIME_PASSWORD="$RUNTIME_PASSWORD" \
    --set OWNER_PASSWORD="$OWNER_PASSWORD" \
    --set BYPASS_PASSWORD="$BYPASS_PASSWORD" \
    --set MEMBER_PASSWORD="$MEMBER_PASSWORD" \
    --set REPLICATOR_PASSWORD="$REPLICATOR_PASSWORD" \
    --set MIXED_CASE_PASSWORD="$MIXED_CASE_PASSWORD" <<SQL
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
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${UNSETTABLE_ROLE}') THEN
        CREATE ROLE ${UNSETTABLE_ROLE};
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${MIXED_CASE_ROLE}') THEN
        CREATE ROLE "${MIXED_CASE_ROLE}" LOGIN;
    END IF;
END \$\$;

-- NOSUPERUSER NOBYPASSRLS NOCREATEROLE spelled out rather than left to the default: the whole suite is
-- vacuous if the runtime role acquires any of them, and a default is not a guarantee.
-- NOREPLICATION is spelled out with the rest, and it is the one whose omission mattered most: a role with
-- REPLICATION and no other privilege passes every SQL-level check and then hands over the whole cluster via
-- pg_basebackup. Round 5 demonstrated that the "re-running repairs a drifted role" promise above was false
-- for exactly this attribute.
ALTER ROLE ${RUNTIME_ROLE} WITH LOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION NOCREATEROLE NOCREATEDB
    PASSWORD :'RUNTIME_PASSWORD';
ALTER ROLE ${OWNER_ROLE}   WITH LOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION NOCREATEROLE CREATEDB
    PASSWORD :'OWNER_PASSWORD';
ALTER ROLE ${BYPASS_ROLE}  WITH LOGIN NOSUPERUSER BYPASSRLS NOREPLICATION NOCREATEROLE NOCREATEDB
    PASSWORD :'BYPASS_PASSWORD';
ALTER ROLE ${MEMBER_ROLE}  WITH LOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION NOCREATEROLE NOCREATEDB
    PASSWORD :'MEMBER_PASSWORD';
-- REPLICATION and nothing else. Note it is NOT BYPASSRLS and NOT superuser: that is the whole point, since
-- both of those were already detected and this one was not.
ALTER ROLE ${REPLICATOR_ROLE} WITH LOGIN NOSUPERUSER NOBYPASSRLS REPLICATION NOCREATEROLE NOCREATEDB
    PASSWORD :'REPLICATOR_PASSWORD';
-- The superuser's password, so the REQUIRED credential in api/phpunit.xml can actually authenticate. Only the
-- password is touched: no attribute of the superuser role is altered. Reached only with explicit consent --
-- see THE THROWAWAY-CLUSTER CONSENT GUARD above; this is the one statement that leaves ${DB} and the one a
-- re-run cannot undo.
ALTER ROLE :"SUPERUSER_ROLE" WITH PASSWORD :'SUPERUSER_PASSWORD';

ALTER ROLE ${TRUNCATOR_ROLE} WITH NOLOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION;
ALTER ROLE ${PROBE_OWNER_ROLE} WITH NOLOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION;
ALTER ROLE ${UNSETTABLE_ROLE} WITH NOLOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION;
-- Restricted exactly like the runtime role: the point is the NAME, not extra privilege.
ALTER ROLE "${MIXED_CASE_ROLE}" WITH LOGIN NOSUPERUSER NOBYPASSRLS NOREPLICATION NOCREATEROLE
    NOCREATEDB PASSWORD :'MIXED_CASE_PASSWORD';

-- The two grants that make the reachability tests possible, and ONLY on the probe role. Granting
-- either of these to ${RUNTIME_ROLE} is the misconfiguration the suite exists to detect.
GRANT ${BYPASS_ROLE} TO ${MEMBER_ROLE};
GRANT ${RUNTIME_ROLE} TO ${MEMBER_ROLE};

-- WITH INHERIT FALSE, deliberately: the runtime role does not hold the truncator's privileges by default,
-- so has_table_privilege() answers "no" while one SET ROLE reaches them. This is the shape the isolation
-- check must refuse, and it cannot be tested unless the fixture can express it.
GRANT ${TRUNCATOR_ROLE} TO ${RUNTIME_ROLE} WITH INHERIT FALSE;

-- WITH INHERIT FALSE, SET FALSE: held, but unreachable. This is the ONLY grant shape under which
-- pg_has_role(..., 'MEMBER') and pg_has_role(..., 'SET') disagree, and therefore the only fixture that can tell
-- roleCanBeAssumedSql()'s two modes apart. Re-granted unconditionally rather than guarded by an existence check,
-- because a grant that drifted to SET TRUE would silently make every assertion about it vacuous again.
REVOKE ${UNSETTABLE_ROLE} FROM ${RUNTIME_ROLE};
GRANT ${UNSETTABLE_ROLE} TO ${RUNTIME_ROLE} WITH INHERIT FALSE, SET FALSE;

-- ADMIN OPTION so the OWNER connection can grant and revoke this role inside a test. Needed because the
-- ownership-reachability axis is otherwise untestable: the existing test connects AS the table's owner, and a
-- role always satisfies pg_has_role() on itself under every mode, so MEMBER -> USAGE survived as an
-- apparently-equivalent mutant until this role existed. It now DIES — the claim of equivalence was wrong,
-- which is why the role is here. What has to be proven is a role REACHING an owner it does not inherit, and only a
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
-- Quoted, or PostgreSQL folds the name and grants to a role that does not exist.
GRANT CONNECT ON DATABASE ${DB} TO "${MIXED_CASE_ROLE}";
-- TEMPORARY, granted back explicitly after the REVOKE above took it from PUBLIC. The column-fidelity suite
-- creates a TEMPORARY table so it needs no DDL on the schema and leaves nothing behind — a stray permanent
-- table would break the tenancy suite's policed-table counts.
--
-- THIS GRANT IS NOT FREE, and the sentence here previously said it was: "a temporary table is session-private
-- and can only ever hold rows the role could already read". Session-private is the problem, not the reassurance.
-- A temporary table outlives the transaction-scoped tenant binding under which its rows were selected, carries
-- no row-level security of its own, and is in no policed inheritance hierarchy — so it is readable under
-- whatever tenant is bound to that connection NEXT. Certification round 11 read tenant A's rows while bound to
-- tenant B through one, with every other guard reporting clean. "Rows the role could already read" conflates
-- the ROLE's lifetime privileges with the TENANT's transaction-scoped scope, and the whole design rests on
-- those being different.
-- Kept because the suite needs it and PostgreSQL offers no narrower grant; made safe instead by
-- PostgresRowLevelSecurityIsolation::assertNoSessionLifetimeDataIsMaterialised() at acquisition and
-- ::discardSessionState() at release. In production this grant should simply be absent.
GRANT TEMPORARY ON DATABASE ${DB} TO ${RUNTIME_ROLE};
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

# The superuser is named here, not only in the guard above. It is the one role this script changes that it did
# not create and that lives outside ${DB}, so a summary listing only the database and the runtime role leaves
# the cluster-global change with no trace in the output at all -- which is how an overwritten operator password
# becomes undiagnosable.
echo "provision-test-database: OK — ${DB} owned by ${OWNER_ROLE}; ${RUNTIME_ROLE} is a restricted non-owner."
echo "provision-test-database: the PASSWORD of cluster-global superuser role \"${SUPERUSER_ROLE}\" was OVERWRITTEN (shared pg_authid — this reaches every database on this cluster, not just ${DB})."
