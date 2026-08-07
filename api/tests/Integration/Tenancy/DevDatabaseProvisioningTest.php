<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration\Tenancy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * `scripts/dev/provision-dev-database.sh`, EXECUTED — the DEV database's topology, which nothing provisioned.
 *
 * **WHY THIS EXISTS.** `provision-test-database.sh` gets `twes_in_test` right and nothing got `twes_in` right:
 * `build-waves.plan.md`'s 2026-08-01 entry records that the dev database was owned by the RUNTIME role, so `public`
 * belonged to `pg_database_owner` and `twes` held implicit `CREATE` — one `CREATE TABLE` away from owning a
 * tenant-owned table, and an owner can `ALTER TABLE … DISABLE ROW LEVEL SECURITY` in one statement. It was corrected
 * BY HAND, which means every fresh container reproduced the wrong shape. `schema-tenancy.php` catches the
 * consequence, on a migrated database, after the fact; this is the thing that makes the shape right to begin with.
 *
 * **IT RUNS THE SCRIPT RATHER THAN READING IT.** `CLAUDE.md` § Gotchas, 2026-07-29: *"a test that greps source
 * instead of running code proves nothing"*. Every assertion below is made against a real cluster after a real
 * invocation.
 *
 * **AND IT PROVES THE PRIVILEGES BEHAVIOURALLY, not out of `pg_default_acl`.** Reading the catalogue would assert
 * that the right statement was issued; creating a table as the owner and then asking whether the runtime role can
 * `TRUNCATE` it asserts what actually happens to the next table a migration creates — which is the thing the
 * ALTER DEFAULT PRIVILEGES exists to control.
 *
 * The role names are OVERRIDDEN to a throwaway pair, for two reasons: the cluster already carries the twelve roles
 * `provision-test-database.sh` builds, so asserting against `twes` would be asserting about state this script did not
 * create; and it is the only way to prove the script creates exactly TWO roles and gives neither of them a dangerous
 * attribute.
 */
#[CoversNothing]
final class DevDatabaseProvisioningTest extends TestCase
{
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_dev_provision_probe';
    private const RUNTIME_ROLE = 'twes_devprobe_app';
    private const OWNER_ROLE = 'twes_devprobe_owner';
    private const FOREIGN_ROLE = 'twes_devprobe_stranger';

    public static function setUpBeforeClass(): void
    {
        self::cleanUpProbeRoles();
    }

    protected function setUp(): void
    {
        self::cleanUpProbeRoles();
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanUpProbeRoles();
    }

    /**
     * **THE WHOLE POINT: the runtime role must not be able to CREATE in `public`, and the database must not own it.**
     *
     * Both halves, because either alone is insufficient. A database owned by the runtime role gives `public` to
     * `pg_database_owner`, and `pg_database_owner`'s implicit membership hands `CREATE` to the owner without any
     * explicit grant existing to find — so an audit that only looked for grants would report clean.
     */
    public function testTheRuntimeRoleCanNeitherOwnTheDatabaseNorCreateInItsSchema(): void
    {
        self::provision();

        self::assertSame(
            self::OWNER_ROLE,
            self::databaseOwner(),
            'the DATABASE must be owned by the owner role — otherwise `public` belongs to `pg_database_owner` and the '
            . 'runtime role holds CREATE through an implicit membership with no grant to find',
        );

        self::assertFalse(
            self::schemaPrivilege(self::RUNTIME_ROLE, 'CREATE'),
            'the runtime role must NOT be able to create in `public`: a table it created would be a table it OWNS, '
            . 'and an owner can ALTER TABLE … DISABLE ROW LEVEL SECURITY in one statement',
        );

        // NOTE: on a DEFAULT cluster this assertion passes whether or not the script grants USAGE, because `PUBLIC`
        // still holds it on PostgreSQL 15+. It is kept as a statement of the requirement; the case that makes the
        // explicit grant load-bearing is `testItRepairsAPre15OrHandModifiedSchemaPrivilegeShape()`.
        self::assertTrue(
            self::schemaPrivilege(self::RUNTIME_ROLE, 'USAGE'),
            'but it must be able to USE the schema, or the application cannot read its own tables',
        );

        self::assertTrue(
            self::schemaPrivilege(self::OWNER_ROLE, 'CREATE'),
            'and the owner must be able to create, or migrations cannot run',
        );
    }

    /**
     * DEFAULT PRIVILEGES GIVE THE RUNTIME ROLE DML AND **NEVER `TRUNCATE`** — asserted on a table created afterwards.
     *
     * `TRUNCATE` is never subject to row security at any privilege level, so a runtime role holding it can erase
     * every tenant's rows while every policy remains in place. `BehaviouralIsolationTest` attacks that grant on the
     * test database; this is what stops it existing on the dev one.
     *
     * The table is created AFTER provisioning, by the owner, which is the only way to test a DEFAULT privilege: it
     * applies to objects that do not exist yet. Asserting `pg_default_acl` instead would prove a statement ran, not
     * that the next migration's tables come out right.
     */
    public function testTheRuntimeRoleGetsDmlOnFutureTablesButNeverTruncate(): void
    {
        self::provision();

        self::asOwner('CREATE TABLE later_arrival (id int PRIMARY KEY)');

        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            self::assertTrue(
                self::tablePrivilege(self::RUNTIME_ROLE, 'later_arrival', $privilege),
                $privilege . ' must be granted on a table the owner creates after provisioning',
            );
        }

        self::assertFalse(
            self::tablePrivilege(self::RUNTIME_ROLE, 'later_arrival', 'TRUNCATE'),
            'TRUNCATE must NOT be granted — it bypasses row security at every privilege level, so it erases every '
            . 'tenant\'s rows with all policies intact',
        );
    }

    /**
     * IT CREATES EXACTLY TWO ROLES AND NEITHER IS DANGEROUS.
     *
     * `build-waves.plan.md` is explicit that this is *"deliberately NOT the test script's twelve roles — `BYPASSRLS`
     * and `REPLICATION` fixtures exist to make dangerous shapes testable and have no business in a development
     * database"*. A fixture role that leaked into a developer's own database would be a role that bypasses row
     * security entirely, sitting on the cluster their browser talks to.
     *
     * Asserted by listing what actually exists with the probe prefix, so adding a third role to the script fails
     * here rather than being noticed later.
     */
    public function testItCreatesTwoRolesAndGivesNeitherADangerousAttribute(): void
    {
        self::provision();

        $roles = self::superuserConnection()->query(
            "SELECT rolname, rolsuper, rolbypassrls, rolreplication, rolcreaterole, rolcreatedb"
            . " FROM pg_roles WHERE rolname LIKE 'twes_devprobe%' ORDER BY rolname",
        );
        self::assertNotFalse($roles);

        /** @var list<array<string, mixed>> $rows */
        $rows = $roles->fetchAll(\PDO::FETCH_ASSOC);

        self::assertSame(
            // Alphabetical, matching the query's own `ORDER BY rolname` — `..._app` sorts before `..._owner`.
            [self::RUNTIME_ROLE, self::OWNER_ROLE],
            array_map(static fn(array $r): string => (string) $r['rolname'], $rows),
            'exactly two roles, and no BYPASSRLS or REPLICATION fixture among them',
        );

        foreach ($rows as $row) {
            foreach (['rolsuper', 'rolbypassrls', 'rolreplication', 'rolcreaterole', 'rolcreatedb'] as $attribute) {
                self::assertFalse(
                    self::isPostgresTrue($row[$attribute]),
                    \sprintf('%s must not hold %s', $row['rolname'], $attribute),
                );
            }
        }
    }

    /**
     * **THE RUNTIME ROLE IS NOT A MEMBER OF THE OWNER**, which is the grant that quietly reopens everything.
     *
     * `provision-test-database.sh` has a commented-out line saying exactly this, because it is the ordinary
     * convenience wiring somebody adds to make a permission error go away: with it, the runtime role can `SET ROLE`
     * to the table owner and disable a policy. The test database proves the un-granted shape; so must the dev one.
     */
    public function testTheRuntimeRoleCannotAssumeTheOwner(): void
    {
        self::provision();

        $reachable = self::superuserConnection()->query(\sprintf(
            "SELECT pg_has_role('%s', '%s', 'USAGE') OR pg_has_role('%s', '%s', 'SET')",
            self::RUNTIME_ROLE,
            self::OWNER_ROLE,
            self::RUNTIME_ROLE,
            self::OWNER_ROLE,
        ));
        self::assertNotFalse($reachable);

        self::assertFalse(
            self::isPostgresTrue($reachable->fetchColumn()),
            'the runtime role must not be able to reach the owner by inheritance OR by SET ROLE — either one makes '
            . 'every policy removable by the application\'s own credential',
        );
    }

    /** RE-RUNNABLE. A developer runs this before migrating and again after; the second run must not fail or change. */
    public function testItIsIdempotent(): void
    {
        self::provision();
        self::asOwner('CREATE TABLE survivor (id int PRIMARY KEY)');

        self::provision();

        self::assertSame(self::OWNER_ROLE, self::databaseOwner(), 'ownership unchanged');
        self::assertFalse(self::schemaPrivilege(self::RUNTIME_ROLE, 'CREATE'), 'still no CREATE');
        self::assertTrue(
            self::tablePrivilege(self::RUNTIME_ROLE, 'survivor', 'SELECT'),
            'and the table the first run\'s default privileges covered is still readable — a second run must not '
            . 'revoke what the first granted',
        );
    }

    /**
     * **IT REFUSES A DATABASE HOLDING SOMEBODY ELSE'S OBJECTS**, which is the guard that replaces the test script's.
     *
     * `provision-test-database.sh` refuses a database that holds ANY relation, because a test database is a
     * throwaway. That guard cannot transfer: a dev database legitimately holds migrated tables, and this script has
     * to be re-runnable. So the question becomes *whose* objects are in there — a relation owned by a role that is
     * neither ours means this is a shared or foreign database, and `REVOKE CREATE ON SCHEMA public FROM PUBLIC` on
     * one of those breaks whatever else was using it.
     *
     * A refusal must also CHANGE NOTHING, which the second half asserts: the database's ownership is still the
     * stranger's afterwards.
     */
    public function testItRefusesADatabaseHoldingAForeignRolesObjects(): void
    {
        $superuser = self::superuserConnection();
        $superuser->exec(\sprintf('CREATE ROLE %s NOLOGIN', self::FOREIGN_ROLE));
        $superuser->exec(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::DATABASE));
        $superuser->exec(\sprintf('CREATE DATABASE %s OWNER %s', self::DATABASE, self::FOREIGN_ROLE));

        self::connectionTo(self::DATABASE, self::superuserName(), self::superuserPassword())
            ->exec(\sprintf('CREATE TABLE not_ours (id int); ALTER TABLE not_ours OWNER TO %s', self::FOREIGN_ROLE));

        [$status, $output] = self::runProvisioner();

        self::assertNotSame(0, $status, "the script must refuse:\n" . $output);
        self::assertStringContainsString('not_ours', $output, 'and it must NAME the relation that made it refuse');

        self::assertSame(
            self::FOREIGN_ROLE,
            self::databaseOwner(),
            'a refusal must change nothing — the database is still owned by the stranger',
        );
    }

    /**
     * **IT CORRECTS A DATABASE ALREADY OWNED BY THE RUNTIME ROLE — the exact defect this script exists for.**
     *
     * This is the state `build-waves.plan.md` records finding on the real dev cluster on 2026-08-01, and every case
     * above provisions a database that does not exist yet, so none of them exercises the correction at all: on a fresh
     * database the ownership comes from `CREATE DATABASE ... OWNER`, and `ALTER DATABASE ... OWNER TO` is a no-op. Only
     * this case makes that statement load-bearing.
     *
     * **The first half is the detector, and it asserts the BUG before asserting the fix.** With the database owned by
     * the runtime role, `public` belongs to `pg_database_owner`, whose implicit membership hands the owner `CREATE` —
     * so `has_schema_privilege` returns true with no grant anywhere for an audit to find. That assertion is what
     * demonstrates the gap is real rather than theoretical, which is what Rule 7 asks of an infrastructure change.
     */
    public function testItCorrectsADatabaseAlreadyOwnedByTheRuntimeRole(): void
    {
        $superuser = self::superuserConnection();

        // Provisioned the careless way: both roles exist and the database belongs to the APPLICATION's role.
        $superuser->exec(\sprintf("CREATE ROLE %s LOGIN PASSWORD 'devprobe'", self::RUNTIME_ROLE));
        $superuser->exec(\sprintf("CREATE ROLE %s LOGIN PASSWORD 'devprobe'", self::OWNER_ROLE));
        $superuser->exec(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::DATABASE));
        $superuser->exec(\sprintf('CREATE DATABASE %s OWNER %s', self::DATABASE, self::RUNTIME_ROLE));

        self::assertTrue(
            self::schemaPrivilege(self::RUNTIME_ROLE, 'CREATE'),
            'THE DETECTOR: a database owned by the runtime role hands it CREATE on `public` through '
            . '`pg_database_owner`, with no explicit grant to find. If this assertion ever fails, PostgreSQL changed '
            . 'and the rest of this case is testing nothing.',
        );

        self::provision();

        self::assertSame(self::OWNER_ROLE, self::databaseOwner(), 'ownership must be CORRECTED, not left alone');
        self::assertFalse(
            self::schemaPrivilege(self::RUNTIME_ROLE, 'CREATE'),
            'and the implicit CREATE must be gone with it',
        );
    }

    /**
     * **IT CORRECTS A PRE-EXISTING ROLE'S DANGEROUS ATTRIBUTES — R1-4.**
     *
     * The script skipped every check for a role that already existed: it probed `pg_roles`, found the name, and
     * `continue`d. So a `twes` left over as SUPERUSER or BYPASSRLS from an experiment was accepted in silence, and
     * `testItCreatesTwoRolesAndGivesNeitherADangerousAttribute()` could not see it, because that case runs against a
     * clean cluster where the roles are created fresh with the attributes spelled out in the `CREATE`.
     *
     * That is not a cosmetic gap. A SUPERUSER or `BYPASSRLS` runtime role means row-level security **never applies**
     * to the application's own connection — every tenant's documents readable through the ordinary code path, with
     * the schema gate still reporting clean, because that gate reads the policies rather than asking who can ignore
     * them. `REPLICATION` is the same breach by another road: `pg_basebackup` reads the whole cluster with row
     * security never involved.
     *
     * **The password is still never overwritten, and that asymmetry is deliberate.** A password is a developer's
     * own choice and the script must not clobber the test fixture's; an attribute that defeats tenant isolation is
     * not a choice this script can defer to. So attributes are corrected and credentials are left alone.
     */
    public function testItCorrectsADangerousAttributeOnAPreExistingRole(): void
    {
        $superuser = self::superuserConnection();

        // BOTH roles pre-created the careless way, and the runtime one carrying every attribute that matters.
        $superuser->exec(\sprintf(
            "CREATE ROLE %s LOGIN PASSWORD 'devprobe' SUPERUSER BYPASSRLS REPLICATION CREATEROLE CREATEDB",
            self::RUNTIME_ROLE,
        ));
        $superuser->exec(\sprintf("CREATE ROLE %s LOGIN PASSWORD 'devprobe'", self::OWNER_ROLE));

        // THE DETECTOR. If this ever fails, the fixture is not producing the dangerous shape and everything below is
        // asserting nothing — the *a fixture that cannot express a dangerous shape cannot detect it* rule.
        self::assertTrue(self::roleHolds(self::RUNTIME_ROLE, 'rolbypassrls'), 'the fixture must start dangerous');
        self::assertTrue(self::roleHolds(self::RUNTIME_ROLE, 'rolsuper'), 'the fixture must start dangerous');

        self::provision();

        foreach (['rolsuper', 'rolbypassrls', 'rolreplication', 'rolcreaterole', 'rolcreatedb'] as $attribute) {
            self::assertFalse(
                self::roleHolds(self::RUNTIME_ROLE, $attribute),
                \sprintf(
                    '%s must have %s REMOVED, not merely never granted: a role that already existed is exactly the '
                    . 'case the script skipped, and BYPASSRLS or SUPERUSER on the application credential means row '
                    . 'security never applies to it at all',
                    self::RUNTIME_ROLE,
                    $attribute,
                ),
            );
        }
    }

    /**
     * **IT REASSIGNS RELATIONS OWNED BY THE RUNTIME ROLE — R1-3.**
     *
     * The script corrected the DATABASE owner and the SCHEMA owner and never a RELATION owner. That leaves the exact
     * state § Gotchas 2026-08-01 records as a P0: `doctrine_migration_versions` in this repository's own dev database
     * was owned by the runtime role, one `ALTER TABLE … DISABLE ROW LEVEL SECURITY` from every tenant's data. `FORCE
     * ROW LEVEL SECURITY` does not help — it stops an owner SKIPPING a policy, not REMOVING one.
     *
     * It is also invisible to every other check. `schema-tenancy.php` refuses a schema whose tenant tables the
     * runtime role owns, so a fresh migration is caught — but this script's whole reason to exist is a database that
     * is ALREADY in the wrong shape, and a developer who runs it expects to be told it is fixed. The
     * foreign-role refusal does not fire either: the runtime role is one of the script's own two, not a stranger.
     */
    public function testItReassignsRelationsOwnedByTheRuntimeRole(): void
    {
        $superuser = self::superuserConnection();
        $superuser->exec(\sprintf("CREATE ROLE %s LOGIN PASSWORD 'devprobe'", self::RUNTIME_ROLE));
        $superuser->exec(\sprintf("CREATE ROLE %s LOGIN PASSWORD 'devprobe'", self::OWNER_ROLE));
        $superuser->exec(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::DATABASE));

        // The DATABASE owned correctly, so this case isolates the RELATION axis — otherwise the ownership correction
        // already under test could account for the result and the new statement would be unpinned.
        $superuser->exec(\sprintf('CREATE DATABASE %s OWNER %s', self::DATABASE, self::OWNER_ROLE));

        $inDatabase = self::connectionTo(self::DATABASE, self::superuserName(), self::superuserPassword());
        $inDatabase->exec(\sprintf('GRANT CREATE ON SCHEMA public TO %s', self::RUNTIME_ROLE));
        $inDatabase->exec(\sprintf('SET ROLE %s', self::RUNTIME_ROLE));
        $inDatabase->exec('CREATE TABLE migration_versions (version text PRIMARY KEY)');
        $inDatabase->exec('RESET ROLE');

        // THE DETECTOR, and it is the whole point: an owner can remove a policy from its own table whatever FORCE
        // says, so this ownership IS the breach rather than a step towards one.
        self::assertSame(
            self::RUNTIME_ROLE,
            self::relationOwner('migration_versions'),
            'the fixture must start with the relation owned by the runtime role',
        );

        self::provision();

        self::assertSame(
            self::OWNER_ROLE,
            self::relationOwner('migration_versions'),
            'the relation must be REASSIGNED: an owner can ALTER TABLE ... DISABLE ROW LEVEL SECURITY on its own '
            . 'table, and FORCE stops an owner SKIPPING a policy rather than REMOVING one',
        );
    }

    /**
     * **IT REPAIRS A `public` SCHEMA CARRYING A PRE-15 OR HAND-MODIFIED PRIVILEGE SHAPE.**
     *
     * FOUR statements in the script are DEFENSIVE (three here, one in the sibling case below) — they describe a state a default PostgreSQL 18 cluster is not in —
     * and every one of them was **unobservable** until this case existed. Mutants deleting
     * `REVOKE CREATE ON SCHEMA public FROM PUBLIC` and `GRANT USAGE ON SCHEMA public TO <runtime>` each left the whole
     * suite green, because on this cluster `PUBLIC` already lacks `CREATE` and already holds `USAGE`
     * [Verified: `has_schema_privilege('public','public','CREATE'), … 'USAGE'` → `f, t` on PostgreSQL 18.4]. That is
     * the fixture-reach problem `CLAUDE.md` records against the tenancy gate: *a probe's reach is bounded by its
     * fixture's value space*, so a fixture in the default state cannot see a statement that only matters away from it.
     *
     * The three shapes covered HERE, each real rather than invented:
     *
     * 1. **`PUBLIC` holds `CREATE`** — the default on every cluster before PostgreSQL 15, so any database restored
     *    from an older dump arrives this way, and it lets ANY role create a table in `public`.
     * 2. **`PUBLIC` does not hold `USAGE`** — a hardened cluster (`REVOKE ALL ON SCHEMA public FROM PUBLIC`) is
     *    current best practice, and on one of those the application cannot read its own tables unless the grant is
     *    explicit.
     * 3. **The runtime role holds `CREATE` by an EXPLICIT grant** — the most likely of the three, because it is what
     *    somebody types to make a permission error go away. Revoking from `PUBLIC` does not touch it.
     *
     * **The fourth shape — `public` owned by a concrete role — is DELIBERATELY IN ITS OWN CASE**
     * ({@see self::testItReturnsAConcretelyOwnedPublicSchemaToTheDatabaseOwner()}), because the two fixtures
     * INTERFERE and combining them made shape 3 untestable. Granting `CREATE` to a role that already OWNS the schema
     * adds no distinguishable ACL entry — the privilege is implicit in ownership — so reassigning the owner carried
     * it away and the mutant deleting `REVOKE CREATE … FROM <runtime>` survived. [Verified by hand: with `public`
     * owned by `pg_database_owner` the grant appears as `<runtime>=C/pg_database_owner` and persists through
     * `ALTER SCHEMA … OWNER TO`; with `public` owned by `<runtime>` it does not appear at all.] Two fixtures in one
     * case is how one of them ends up proving nothing.
     *
     * Broken AFTER a clean provision rather than before, which is both simpler and more honest about the case being
     * defended: this is a database that was right and then a restore or a well-meaning `GRANT` changed it, and the
     * script has to put it back.
     */
    public function testItRepairsAPre15OrHandModifiedSchemaPrivilegeShape(): void
    {
        self::provision();

        $inDatabase = self::connectionTo(self::DATABASE, self::superuserName(), self::superuserPassword());
        $inDatabase->exec('GRANT CREATE ON SCHEMA public TO PUBLIC');
        $inDatabase->exec('REVOKE USAGE ON SCHEMA public FROM PUBLIC');
        $inDatabase->exec(\sprintf('GRANT CREATE ON SCHEMA public TO %s', self::RUNTIME_ROLE));

        // THE DETECTOR. Each of the three shapes is asserted to be present, so a PostgreSQL change that made any of
        // them impossible would fail here rather than silently making the repair assertions vacuous.
        self::assertTrue(
            self::schemaPrivilege(self::RUNTIME_ROLE, 'CREATE'),
            'the fixture must actually reproduce the defect — the runtime role can create in `public` right now',
        );
        self::assertFalse(
            self::schemaPrivilege('public', 'USAGE'),
            'and PUBLIC must really have lost USAGE, or the grant below proves nothing',
        );

        self::provision();

        self::assertFalse(
            self::schemaPrivilege(self::RUNTIME_ROLE, 'CREATE'),
            'the runtime role must lose CREATE again — this single assertion is what makes TWO otherwise invisible '
            . 'statements load-bearing: the REVOKE from PUBLIC and the REVOKE from the runtime role itself. Deleting '
            . 'either one turns it red.',
        );
        self::assertTrue(
            self::schemaPrivilege(self::RUNTIME_ROLE, 'USAGE'),
            'and it must still be able to USE the schema on a cluster where PUBLIC does not grant it — which is what '
            . 'makes the explicit GRANT USAGE load-bearing rather than redundant',
        );

    }

    /**
     * A `public` SCHEMA OWNED BY A CONCRETE ROLE IS HANDED BACK TO `pg_database_owner`.
     *
     * The fourth defensive statement, in its own case for the interference reason the sibling above explains. This is
     * the pre-15 restored-dump shape: before PostgreSQL 15, `public` was owned by whoever created the database, and if
     * that role is the application's it keeps `CREATE` no matter who owns the database — which is the whole defect
     * this script exists to correct, arriving through a second door.
     */
    public function testItReturnsAConcretelyOwnedPublicSchemaToTheDatabaseOwner(): void
    {
        self::provision();

        $inDatabase = self::connectionTo(self::DATABASE, self::superuserName(), self::superuserPassword());
        $inDatabase->exec(\sprintf('ALTER SCHEMA public OWNER TO %s', self::RUNTIME_ROLE));

        self::assertTrue(
            self::schemaPrivilege(self::RUNTIME_ROLE, 'CREATE'),
            'THE DETECTOR: owning `public` gives CREATE implicitly, with no grant to find',
        );

        self::provision();

        $owner = $inDatabase->query("SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = 'public'");
        self::assertNotFalse($owner);
        self::assertSame('pg_database_owner', (string) $owner->fetchColumn(), '`public` must be handed back');
        self::assertFalse(
            self::schemaPrivilege(self::RUNTIME_ROLE, 'CREATE'),
            'and the implicit CREATE goes with the ownership',
        );
    }

    /**
     * And it fails LOUDLY rather than silently when the cluster is unreachable, WITH A USABLE MESSAGE.
     *
     * The exit code alone is not enough — `CLAUDE.md` § Gotchas records that a crash and a detection are
     * indistinguishable without asserting the output, and this case passed vacuously before the script existed
     * because a missing file also exits non-zero. The message must also name the two-cluster trap: on this container
     * a `Connection refused` on 5432 usually means the wrong PostgreSQL cluster holds the port, and a refusal that
     * does not say so sends a reader hunting for a firewall.
     */
    public function testItFailsRatherThanSkippingWhenItCannotReachTheCluster(): void
    {
        [$status, $output] = self::runProvisioner(['PGPORT' => '1']);

        self::assertNotSame(0, $status, "an unreachable cluster must be a failure:\n" . $output);
        self::assertStringContainsString('CANNOT REACH THE CLUSTER', $output);
        self::assertStringContainsString('pg_ctlcluster', $output, 'the message must name the two-cluster remedy');
    }

    // ------------------------------------------------------------------ helpers

    /** Run the provisioner and require success. */
    private static function provision(): void
    {
        [$status, $output] = self::runProvisioner();

        self::assertSame(0, $status, "provisioning must succeed:\n" . $output);
    }

    /**
     * @param array<string, string> $extraEnvironment
     *
     * @return array{int, string}
     */
    private static function runProvisioner(array $extraEnvironment = []): array
    {
        $script = \dirname(__DIR__, 4) . '/scripts/dev/provision-dev-database.sh';

        // libpq's OWN environment variables carry the connection, which is why the script issues no `--host`/`--user`
        // flags of its own: a developer runs it under `sudo -u postgres` on a local socket, and this runs it over TCP
        // as the test superuser. One script, both paths, and no connection flags to keep in step.
        $environment = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'PGHOST' => self::host(),
            'PGPORT' => self::port(),
            'PGUSER' => self::superuserName(),
            'PGPASSWORD' => self::superuserPassword(),
            'TWES_DEV_DB_NAME' => self::DATABASE,
            'TWES_DEV_DB_USER' => self::RUNTIME_ROLE,
            'TWES_DEV_DB_PASSWORD' => 'devprobe',
            'TWES_DEV_DB_OWNER_USER' => self::OWNER_ROLE,
            'TWES_DEV_DB_OWNER_PASSWORD' => 'devprobe',
            ...$extraEnvironment,
        ];

        $assignments = '';

        foreach ($environment as $name => $value) {
            $assignments .= $name . '=' . escapeshellarg($value) . ' ';
        }

        // `env -i` so the run cannot inherit a PG* variable from the suite's own shell and pass for the wrong reason
        // — CLAUDE.md § Gotchas, 2026-08-05: "a suite whose result depends on the shell that launched it is not a
        // suite that has been run".
        exec('env -i ' . $assignments . 'bash ' . escapeshellarg($script) . ' 2>&1', $lines, $status);

        return [$status, implode("\n", $lines)];
    }

    private static function databaseOwner(): string
    {
        $result = self::superuserConnection()->query(\sprintf(
            "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '%s'",
            self::DATABASE,
        ));
        self::assertNotFalse($result);

        return (string) $result->fetchColumn();
    }

    private static function schemaPrivilege(string $role, string $privilege): bool
    {
        $result = self::connectionTo(self::DATABASE, self::superuserName(), self::superuserPassword())->query(
            \sprintf("SELECT has_schema_privilege('%s', 'public', '%s')", $role, $privilege),
        );
        self::assertNotFalse($result);

        return self::isPostgresTrue($result->fetchColumn());
    }

    private static function tablePrivilege(string $role, string $table, string $privilege): bool
    {
        $result = self::connectionTo(self::DATABASE, self::superuserName(), self::superuserPassword())->query(
            \sprintf("SELECT has_table_privilege('%s', 'public.%s', '%s')", $role, $table, $privilege),
        );
        self::assertNotFalse($result);

        return self::isPostgresTrue($result->fetchColumn());
    }

    /** Run DDL as the OWNER, which is what a migration does. */
    private static function asOwner(string $statement): void
    {
        self::connectionTo(self::DATABASE, self::OWNER_ROLE, 'devprobe')->exec($statement);
    }

    /** One role attribute from `pg_roles`, by name — the catalogue rather than a `\d+` transcript. */
    private static function roleHolds(string $role, string $attribute): bool
    {
        // The attribute name is interpolated into the SELECT LIST, which is why it is checked against a closed set
        // first: a column name cannot be a bound parameter, and this class must not become the one place in the suite
        // where a string reaches a query unchecked.
        self::assertContains(
            $attribute,
            ['rolsuper', 'rolbypassrls', 'rolreplication', 'rolcreaterole', 'rolcreatedb', 'rolinherit', 'rolcanlogin'],
            'not a pg_roles attribute this helper knows',
        );

        $result = self::superuserConnection()->query(
            \sprintf("SELECT %s FROM pg_roles WHERE rolname = '%s'", $attribute, $role),
        );
        self::assertNotFalse($result);

        return self::isPostgresTrue($result->fetchColumn());
    }

    /** The owner of one relation in the probe database, or `''` when there is no such relation. */
    private static function relationOwner(string $relation): string
    {
        $result = self::connectionTo(self::DATABASE, self::superuserName(), self::superuserPassword())->query(
            \sprintf(
                'SELECT pg_get_userbyid(c.relowner) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace'
                . " WHERE n.nspname = 'public' AND c.relname = '%s'",
                $relation,
            ),
        );
        self::assertNotFalse($result);

        return (string) $result->fetchColumn();
    }

    /**
     * PostgreSQL booleans arrive as `'1'`/`''` through PDO rather than as PHP booleans, so a bare cast is not enough:
     * `(bool) '0'` is FALSE by luck and `(bool) 'f'` is TRUE. Decided once, here, because CLAUDE.md § Gotchas records
     * a condition implemented on one path and not another.
     */
    private static function isPostgresTrue(mixed $value): bool
    {
        return \in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    /** Drop the probe database and every probe role, in the order PostgreSQL permits. */
    private static function cleanUpProbeRoles(): void
    {
        $superuser = self::superuserConnection();
        $superuser->exec(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::DATABASE));

        // `DROP OWNED BY` before `DROP ROLE`, because a role holding default privileges or owning anything cannot be
        // dropped — and it must run in EVERY database where the role holds them, which after the drop above is only
        // this one. A role that has never existed makes both statements error, so each is attempted independently.
        foreach ([self::RUNTIME_ROLE, self::OWNER_ROLE, self::FOREIGN_ROLE] as $role) {
            // Guarded on existence rather than swallowing the error: an unexpected failure here would otherwise
            // leave cluster-global state behind with nothing said about it.
            $exists = $superuser->query(\sprintf("SELECT 1 FROM pg_roles WHERE rolname = '%s'", $role));

            if (false === $exists || false === $exists->fetchColumn()) {
                continue;
            }

            $superuser->exec(\sprintf('DROP OWNED BY %s CASCADE', $role));
            $superuser->exec(\sprintf('DROP ROLE %s', $role));
        }
    }
}
