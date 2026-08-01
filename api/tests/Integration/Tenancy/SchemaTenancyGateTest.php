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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `scripts/gates/schema-tenancy.php`, exercised against a REAL migrated schema.
 *
 * **This is the clean-fixture case and the violation cases for that gate, and it lives here rather than in
 * `test-gates.sh` for one reason: the gate needs a database and the meta-suite does not have one.** Putting it
 * there would mean the entire meta-suite stops running whenever PostgreSQL is down, which in this container is
 * often. The split is about WHERE the database lives, not about which half of the gate is optional —
 * `test-gates.sh` still owns the gate's database-FREE paths (no DSN, unreachable DSN, the rule set).
 *
 * The gate exists because nothing else in this repository can see an unpoliced tenant table: the runtime check
 * derives its subject set from tables that already HAVE row security, so a table missing it is invisible to that
 * check by construction. So this test matters more than most — a gate believed on its happy path is exactly the
 * false assurance `CLAUDE.md` § Gotchas records four times, and one of those was `test-gates.sh` itself
 * reporting 33/33 for a gate that detected nothing.
 *
 * Each case MUTATES a correctly migrated schema in one way and requires the gate to name it. The clean case runs
 * first and last, so a mutation that failed to revert is visible as a failure of the clean case rather than as a
 * confusing pass somewhere else.
 */
#[CoversNothing]
final class SchemaTenancyGateTest extends TestCase
{
    private const DATABASE = 'twes_schema_gate_probe';

    private static ?\PDO $admin = null;

    public static function setUpBeforeClass(): void
    {
        $superuser = self::superuser();
        $superuser->exec(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::DATABASE));
        $superuser->exec(\sprintf('CREATE DATABASE %s OWNER %s', self::DATABASE, self::ownerRole()));

        // The migration is run by the console, not reimplemented here. That is the point: this test asserts the
        // gate accepts what OUR migration produces, so a hand-built schema would be testing a different thing.
        //
        // **BOTH url variables, and `DATABASE_URL_OWNER` is the load-bearing one.** `doctrine_migrations.yaml`
        // pins migrations to the `owner` connection, so overriding `DATABASE_URL` alone leaves the migration
        // pointed at whatever `.env` says — which is the DEV database. That is not a hypothetical either: it is
        // what this test did for one commit, migrating `twes_in` (already up to date, so exit 0) while its own
        // probe database stayed empty and every case failed with `tenant_owned=0`. `DATABASE_URL` is set as well
        // so the default connection cannot reach a different database than the one under test.
        $migrate = \sprintf(
            'cd %s && DATABASE_URL=%s DATABASE_URL_OWNER=%s php bin/console doctrine:migrations:migrate'
            . ' --no-interaction 2>&1',
            escapeshellarg(\dirname(__DIR__, 3)),
            escapeshellarg(self::ownerUrl()),
            escapeshellarg(self::ownerUrl()),
        );
        exec($migrate, $output, $status);

        self::assertSame(0, $status, "The migration must succeed before the gate can be tested:\n" . implode("\n", $output));

        // The migration reported success -- but success against WHICH database? A run pointed at an
        // already-migrated database also exits 0, which is exactly how the failure above stayed invisible at the
        // one assertion that should have caught it. So assert the tables are HERE.
        $present = self::admin()->query(
            "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace"
            . " WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname LIKE 'document%'",
        );

        self::assertNotFalse($present, 'could not count the migrated tables');
        self::assertSame(
            4,
            (int) $present->fetchColumn(),
            'The migration exited 0 but this database has no document tables, so it migrated a DIFFERENT one. '
            . 'Check that DATABASE_URL_OWNER is overridden: doctrine_migrations.yaml pins migrations to the '
            . '"owner" connection, so overriding DATABASE_URL alone silently targets whatever .env names.',
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::superuser()->exec(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', self::DATABASE));
        self::$admin = null;
    }

    public function testTheGateAcceptsTheSchemaOurMigrationProduces(): void
    {
        [$status, $output] = self::runGate();

        self::assertSame(0, $status, "The gate must accept our own migration's output:\n" . $output);
        self::assertStringContainsString('tenant-owned table(s)', $output);
        // The anti-vacuity line, asserted rather than assumed: a gate that inspected nothing prints OK too.
        self::assertMatchesRegularExpression('/counts — tables=\d+ tenant_owned=[1-9]/', $output);
    }

    /**
     * A runtime role that does not exist must be a FAILURE, not a clean run.
     *
     * Both of this gate's runtime-role assertions degrade silently or violently when the name is wrong, and in
     * opposite directions: `$row['owner'] === $runtimeRole` simply never matches, so the ownership axis reports
     * clean on a schema it never checked, while `has_table_privilege('typo', …)` raises and the gate dies. A
     * silent pass on a security axis is the shape CLAUDE.md § Gotchas records repeatedly, and the name is easy to
     * get wrong: it falls back through `TWES_SCHEMA_RUNTIME_ROLE`, then `TWES_TEST_DB_USER`, then the literal
     * `twes`, so any deployment whose runtime role is called something else and sets neither variable lands here.
     */
    public function testTheGateRefusesARuntimeRoleThatDoesNotExist(): void
    {
        [$status, $output] = self::runGate('twes_no_such_role_exists');

        self::assertSame(1, $status, "A non-existent runtime role must fail the gate, not pass it:\n" . $output);
        self::assertStringContainsString('twes_no_such_role_exists', $output);
        self::assertStringContainsString('does not exist', $output);
    }

    /**
     * A database with no tenant-owned table at all must FAIL, not pass — and this branch had no test anywhere.
     *
     * It is the branch that stops the gate certifying an empty or wrong database, which is the failure mode most
     * likely to happen in practice: a CI pointed at an unmigrated database, or at the wrong one of the three
     * URLs this project now carries. `test-gates.sh` asserted "the NINE live assertions (… and an empty schema)
     * are exercised by SchemaTenancyGateTest" while no such case existed — a false coverage claim in the
     * meta-suite whose entire job is refusing false assurance.
     *
     * Uses a throwaway EMPTY database rather than mutating the probe, since the condition is the absence of
     * everything.
     */
    public function testTheGateRefusesADatabaseWithNoTenantOwnedTable(): void
    {
        $empty = self::DATABASE . '_empty';
        $superuser = self::superuser();
        $superuser->exec(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $empty));
        $superuser->exec(\sprintf('CREATE DATABASE %s OWNER %s', $empty, self::ownerRole()));

        try {
            [$status, $output] = self::runGate(null, $empty);

            self::assertSame(1, $status, "An unmigrated database must fail the gate, not pass it:\n" . $output);
            self::assertStringContainsString('asserted nothing', $output);
            self::assertStringContainsString('tenant_owned=0', $output);
        } finally {
            $superuser->exec(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $empty));
        }
    }

    /**
     * A `FOR ALL` policy that omits `WITH CHECK` is CORRECT, and the gate must ACCEPT it.
     *
     * This is the only case here that asserts acceptance of something other than our own migration's output, and
     * it exists because the gate refused it — reporting `WITH CHECK NOT canonical: NULL` and explaining that "a
     * plain INSERT is guarded by WITH CHECK alone", which PostgreSQL refutes: for `FOR ALL` it reuses `USING` as
     * the write check. `policyExpressionIsCanonical(null)` returns true and documents exactly that, so the gate's
     * own docblock claim that all three definitions "agree by construction rather than by review" was false.
     *
     * A false refusal is not a harmless direction. Round 17 records a canonicality judgement applied where it did
     * not belong, which "REFUSED every acquisition, permanently, on an entirely ordinary CREATE POLICY" — a gate
     * that cries wolf on correct schemas gets switched off, and then the real findings go unread too. The
     * cross-tenant INSERT is verified refused by the server below, so the schema really is safe.
     */
    public function testTheGateAcceptsAForAllPolicyThatOmitsWithCheck(): void
    {
        $canonical = \Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation::canonicalPolicyExpression();
        $admin = self::admin();

        $admin->exec('DROP POLICY tenant_isolation ON document');
        $admin->exec(\sprintf('CREATE POLICY tenant_isolation ON document FOR ALL USING (%s)', $canonical));

        try {
            [$status, $output] = self::runGate();

            self::assertSame(
                0,
                $status,
                "A FOR ALL policy omitting WITH CHECK is correct — PostgreSQL reuses USING as the write check:\n"
                . $output,
            );
        } finally {
            $admin->exec('DROP POLICY tenant_isolation ON document');
            $admin->exec(\sprintf(
                'CREATE POLICY tenant_isolation ON document USING (%s) WITH CHECK (%s)',
                $canonical,
                $canonical,
            ));
        }
    }

    /**
     * A runtime role that can BYPASS row-level security must fail the gate, whatever the schema looks like.
     *
     * The role-existence guard added at 8f85b2d reads only that the name resolves. `rolsuper`, `rolbypassrls` and
     * `rolreplication` are one comma away in the same row, and `roleCanBypassPolicies()` already exists as a pure
     * function over exactly that row -- so the gate was asking whether the role was SPELLED right and not whether
     * it was SUBJECT to the policies whose presence it then certifies. A `BYPASSRLS` role reads every tenant with
     * every policy in place, which is the failure mode this whole gate exists to make impossible to miss.
     *
     * Uses the fixture's existing `twes_bypass` rather than altering a role: role attributes are CLUSTER-level, so
     * `ALTER ROLE twes BYPASSRLS` inside a test would escape this database exactly as the superuser password does.
     */
    public function testTheGateRefusesARuntimeRoleThatCanBypassRowLevelSecurity(): void
    {
        [$status, $output] = self::runGate(getenv('TWES_TEST_DB_BYPASS_USER') ?: 'twes_bypass');

        self::assertSame(1, $status, "A BYPASSRLS runtime role must fail the gate:\n" . $output);
        self::assertStringContainsString('bypass', strtolower($output));
    }

    /**
     * A runtime role that is merely a MEMBER of a bypassing role must fail too — and this is the case the fixture
     * was built for and the test above did not use.
     *
     * `rolsuper` and `rolbypassrls` are NOT INHERITED, so `twes_member` reads f/f/f in its own `pg_roles` row while
     * being a member of `twes_bypass`, and reaches the privilege with one `SET ROLE`. Round 22 reproduced the
     * cross-tenant read: gate green, then `SET ROLE twes_bypass` and both tenants' rows.
     *
     * The lesson worth keeping is not about PostgreSQL. `provision-test-database.sh` provisions `twes_member` for
     * exactly this shape — CLAUDE.md § "Quality gate" says so, and says why: *"a fixture that cannot express a
     * dangerous shape cannot detect it"*. The fixture COULD express it; the case above tested the direct attribute
     * instead. A fixture is worth nothing if no case uses it, which is the same false-assurance shape as a gate
     * believed on its happy path.
     */
    public function testTheGateRefusesARuntimeRoleThatIsMerelyAMemberOfABypassingRole(): void
    {
        [$status, $output] = self::runGate(getenv('TWES_TEST_DB_MEMBER_USER') ?: 'twes_member');

        self::assertSame(
            1,
            $status,
            "A role that can SET ROLE to a BYPASSRLS role must fail the gate — the attribute is not inherited, so "
            . "its own pg_roles row reads clean:\n" . $output,
        );
        self::assertStringContainsString('SET ROLE', $output);
    }

    /**
     * @param list<string> $mutation SQL that breaks the schema in exactly one way
     * @param list<string> $revert SQL restoring it
     */
    #[DataProvider('isolationDefects')]
    public function testTheGateRefusesEachIsolationDefect(array $mutation, array $revert, string $expected): void
    {
        $admin = self::admin();

        foreach ($mutation as $statement) {
            $admin->exec($statement);
        }

        try {
            [$status, $output] = self::runGate();

            self::assertSame(1, $status, "The gate must REFUSE this schema:\n" . $output);
            self::assertStringContainsString($expected, $output);
        } finally {
            foreach ($revert as $statement) {
                $admin->exec($statement);
            }
        }

        // The schema is whole again, so the gate must pass. Without this the suite could not tell a mutation that
        // was never reverted from one that was, and every later case would be asserting against a broken schema.
        [$status] = self::runGate();
        self::assertSame(0, $status, 'the mutation must have been reverted');
    }

    /** @return iterable<string, array{list<string>, list<string>, string}> */
    public static function isolationDefects(): iterable
    {
        $canonical = \Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation::canonicalPolicyExpression();

        yield 'row level security disabled' => [
            ['ALTER TABLE document DISABLE ROW LEVEL SECURITY'],
            ['ALTER TABLE document ENABLE ROW LEVEL SECURITY'],
            'has NO row-level security',
        ];

        yield 'FORCE removed, so the owner reads every tenant' => [
            ['ALTER TABLE document NO FORCE ROW LEVEL SECURITY'],
            ['ALTER TABLE document FORCE ROW LEVEL SECURITY'],
            'not FORCE',
        ];

        yield 'an unscoped PERMISSIVE policy beside a correct one' => [
            ['CREATE POLICY reopens_everything ON document USING (true) WITH CHECK (true)'],
            ['DROP POLICY reopens_everything ON document'],
            'not the canonical tenant predicate',
        ];

        yield 'the canonical policy dropped entirely' => [
            ['DROP POLICY tenant_isolation ON document'],
            [\sprintf(
                'CREATE POLICY tenant_isolation ON document USING (%s) WITH CHECK (%s)',
                $canonical,
                $canonical,
            )],
            'NO canonical tenant policy',
        ];

        yield 'the runtime role granted TRUNCATE' => [
            [\sprintf('GRANT TRUNCATE ON document TO %s', self::runtimeRole())],
            [\sprintf('REVOKE TRUNCATE ON document FROM %s', self::runtimeRole())],
            'holds TRUNCATE',
        ];

        yield 'the runtime role made owner' => [
            [\sprintf('ALTER TABLE document_charge OWNER TO %s', self::runtimeRole())],
            [\sprintf('ALTER TABLE document_charge OWNER TO %s', self::ownerRole())],
            'is OWNED by',
        ];

        // A NULLABLE tenant column cannot be produced on our own tables -- `company_id` is in every primary key,
        // so PostgreSQL refuses to drop NOT NULL. That makes the assertion unreachable on the CURRENT schema and
        // reachable on a future table with a surrogate key, which is exactly the shape worth guarding before it
        // exists. So the case builds such a table rather than claiming the branch is untestable.
        yield 'a tenant column that is NULLABLE' => [
            [
                'CREATE TABLE surrogate_key_table (id uuid PRIMARY KEY, company_id uuid, note text)',
                \sprintf('ALTER TABLE surrogate_key_table OWNER TO %s', self::ownerRole()),
                'ALTER TABLE surrogate_key_table ENABLE ROW LEVEL SECURITY',
                'ALTER TABLE surrogate_key_table FORCE ROW LEVEL SECURITY',
                \sprintf(
                    'CREATE POLICY tenant_isolation ON surrogate_key_table USING (%s) WITH CHECK (%s)',
                    $canonical,
                    $canonical,
                ),
            ],
            ['DROP TABLE surrogate_key_table'],
            'is NULLABLE',
        ];

        // ---------------------------------------------------------------- SCOPE: what the gate can even SEE
        //
        // Both reproduced as leaks at 8f85b2d. The gate's charter is that "an unpoliced tenant table is invisible
        // to every other check here" -- which stayed true one schema over, and for a relkind it never selected.
        // The runtime checker filters `nspname NOT IN ('pg_catalog','information_schema')`; this gate pinned
        // `= 'public'`, so it was NARROWER than the control it exists to backstop.

        yield 'a tenant table in another schema, which the public-only scope never saw' => [
            [
                \sprintf('CREATE SCHEMA reporting AUTHORIZATION %s', self::ownerRole()),
                'CREATE TABLE reporting.archive (company_id uuid NOT NULL, id uuid NOT NULL, PRIMARY KEY (company_id, id))',
            ],
            ['DROP SCHEMA reporting CASCADE'],
            'has NO row-level security',
        ];

        // A MATERIALIZED VIEW cannot carry row-level security AT ALL -- PostgreSQL supports no policy on one. So a
        // reporting matview over a policed table is an unpoliced snapshot of it, and REFRESH materialises rows
        // that later readers are never re-filtered against. It carries `company_id`, so by this gate's own
        // classification rule it IS tenant data; the gate simply never selected relkind 'm'.
        yield 'a materialized view holding tenant data, which can never be policed' => [
            ['CREATE MATERIALIZED VIEW tenant_snapshot AS SELECT company_id, id FROM document'],
            ['DROP MATERIALIZED VIEW tenant_snapshot'],
            'cannot carry row-level security',
        ];

        // ---------------------------------------------------------------- COMPOSITE KEYS
        //
        // Three round records call this "the composite-key schema gate" and rate it P0 at the first Wave 1
        // migration, and until round 21 the gate read no pg_constraint or pg_index at all -- the migration gets
        // the keys right and nothing checked that the next one would.
        //
        // The reason it belongs in a TENANCY gate rather than a modelling one: uniqueness and foreign-key checks
        // run with row-level security BYPASSED. PostgreSQL has to see rows the querying tenant cannot, or a
        // constraint would be enforceable only against the rows you can already read. So a key that omits the
        // tenant column is checked across EVERY tenant, and no policy limits it.

        // A single-column FK cannot be built against OUR tables, and that is worth knowing rather than working
        // around: `document`'s primary key is `(company_id, id)`, so no unique constraint exists on `id` alone and
        // PostgreSQL refuses `REFERENCES document (id)` outright with SQLSTATE 42830. The composite key is
        // self-reinforcing. So the case builds the shape it actually guards against — a future table with a
        // SURROGATE key, where `id` alone IS unique and the dangerous FK becomes expressible. Same reasoning as
        // the NULLABLE case above, and the same reason it is worth guarding before such a table exists.
        yield 'a single-column foreign key, which lets one tenant reference another tenant row' => [
            [
                'CREATE TABLE surrogate_parent (id uuid PRIMARY KEY, company_id uuid NOT NULL)',
                'CREATE TABLE surrogate_child (id uuid PRIMARY KEY, company_id uuid NOT NULL, parent_id uuid NOT NULL)',
                'ALTER TABLE surrogate_child ADD CONSTRAINT child_points_at_parent '
                . 'FOREIGN KEY (parent_id) REFERENCES surrogate_parent (id) ON DELETE CASCADE',
                // Both fully isolated, so the FK is the ONLY thing left for the gate to object to.
                ...array_merge(...array_map(
                    static fn(string $t): array => \Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation::policySqlFor($t),
                    ['surrogate_parent', 'surrogate_child'],
                )),
                \sprintf('ALTER TABLE surrogate_parent OWNER TO %s', self::ownerRole()),
                \sprintf('ALTER TABLE surrogate_child OWNER TO %s', self::ownerRole()),
            ],
            ['DROP TABLE surrogate_child', 'DROP TABLE surrogate_parent'],
            'FOREIGN KEY',
        ];

        // A unique constraint omitting the tenant makes tenant B's insert fail because tenant A already used the
        // value -- a cross-tenant existence oracle, and a denial of service on somebody else's numbering.
        yield 'a UNIQUE index that omits the tenant column, which is a cross-tenant oracle' => [
            ['CREATE UNIQUE INDEX leaky_number ON document (number)'],
            ['DROP INDEX leaky_number'],
            'UNIQUE',
        ];

        // ---------------------------------------------------------------- polcmd and polroles
        //
        // A policy can be canonical in both halves and still guard nothing, because the gate read neither which
        // COMMANDS it covers nor which ROLES it applies to. Both fail CLOSED -- the runtime role is denied every
        // row rather than shown another tenant's -- so these are not breaches. They are the gate printing
        // "canonically policed" about a table that is in fact unusable, which is its own defect: a control whose
        // OK sentence is untrue trains the reader to stop believing it.

        yield 'a canonical policy that covers only UPDATE, leaving INSERT and SELECT unpoliced' => [
            [
                'DROP POLICY tenant_isolation ON document',
                \sprintf(
                    'CREATE POLICY tenant_isolation ON document FOR UPDATE USING (%s) WITH CHECK (%s)',
                    $canonical,
                    $canonical,
                ),
            ],
            [
                'DROP POLICY tenant_isolation ON document',
                \sprintf(
                    'CREATE POLICY tenant_isolation ON document USING (%s) WITH CHECK (%s)',
                    $canonical,
                    $canonical,
                ),
            ],
            'does not cover ALL commands',
        ];

        yield 'a canonical policy granted only to another role, so it never applies to the runtime role' => [
            [
                'DROP POLICY tenant_isolation ON document',
                \sprintf(
                    'CREATE POLICY tenant_isolation ON document TO %s USING (%s) WITH CHECK (%s)',
                    self::ownerRole(),
                    $canonical,
                    $canonical,
                ),
            ],
            [
                'DROP POLICY tenant_isolation ON document',
                \sprintf(
                    'CREATE POLICY tenant_isolation ON document USING (%s) WITH CHECK (%s)',
                    $canonical,
                    $canonical,
                ),
            ],
            'does not apply to the runtime role',
        ];

        yield 'a table this gate cannot classify' => [
            ['CREATE TABLE ambiguous (tenant_id uuid NOT NULL, note text)'],
            ['DROP TABLE ambiguous'],
            'cannot tell whether it holds tenant data',
        ];

        // A table with NO tenant column, owned by the RUNTIME role. It holds no tenant data, so nothing about
        // this table leaks — and it must still be refused, because of what it PROVES about the connection that
        // created it: migrations are running as the restricted role, so the next tenant-owned table that
        // migration creates is owned by the runtime role, which is one `ALTER TABLE … DISABLE ROW LEVEL
        // SECURITY` from every tenant's data. This is not hypothetical. It is what `doctrine_migration_versions`
        // in the local `twes_in` database actually was on 2026-08-01, because `.env`'s DATABASE_URL named the
        // runtime role while the comment beside it claimed migrations used a different one — prose asserting a
        // control that nothing implemented, the shape CLAUDE.md § Gotchas records repeatedly. The gate skipped
        // it, because its ownership check was scoped to tables it had already classified as tenant-owned.
        // ---------------------------------------------------------------- REACHABILITY, not just holding
        //
        // Both cases below were CONFIRMED cross-tenant breaches at 8f85b2d, found by two independent reviewers,
        // and both existed because this gate reimplemented a predicate the runtime checker had already got right
        // and documented: "TRUNCATE and ownership are both tested by REACHABILITY, never by has_table_privilege.
        // That function resolves privileges the way PostgreSQL applies them right now -- inheritably -- while
        // SET ROLE is authorised by MEMBERSHIP, so a grant made WITH INHERIT FALSE is invisible to it."
        //
        // `twes_truncator` is granted to the runtime role WITH INHERIT FALSE by provision-test-database.sh, for
        // exactly this purpose: it is held but not inherited, so has_table_privilege says false while
        // `SET ROLE twes_truncator` is one statement away. A fixture that cannot express a dangerous shape cannot
        // detect it -- which is why that role exists and why these cases use it rather than a direct grant.

        yield 'TRUNCATE reachable by SET ROLE, which has_table_privilege cannot see' => [
            [\sprintf('GRANT TRUNCATE ON document TO %s', self::truncatorRole())],
            [\sprintf('REVOKE TRUNCATE ON document FROM %s', self::truncatorRole())],
            'holds TRUNCATE',
        ];

        yield 'an owner the runtime role can SET ROLE to, which string equality cannot see' => [
            [
                \sprintf('GRANT CREATE ON SCHEMA public TO %s', self::truncatorRole()),
                \sprintf('ALTER TABLE document_charge OWNER TO %s', self::truncatorRole()),
            ],
            [
                \sprintf('ALTER TABLE document_charge OWNER TO %s', self::ownerRole()),
                \sprintf('REVOKE CREATE ON SCHEMA public FROM %s', self::truncatorRole()),
            ],
            'is OWNED by',
        ];

        // The `&&` joining the two policy halves had no case that could kill it: the only policy case was
        // `USING (true) WITH CHECK (true)`, wrong on BOTH halves, so `&&` and `||` were indistinguishable to this
        // suite. A reviewer flipped it and all eleven assertions still passed -- while the mutant admitted a real
        // cross-tenant INSERT, because WITH CHECK alone guards a plain INSERT.
        yield 'canonical USING with an unscoped WITH CHECK, which admits a cross-tenant INSERT' => [
            [
                'DROP POLICY tenant_isolation ON document',
                \sprintf('CREATE POLICY tenant_isolation ON document USING (%s) WITH CHECK (true)', $canonical),
            ],
            [
                'DROP POLICY tenant_isolation ON document',
                \sprintf(
                    'CREATE POLICY tenant_isolation ON document USING (%s) WITH CHECK (%s)',
                    $canonical,
                    $canonical,
                ),
            ],
            'not the canonical tenant predicate',
        ];

        yield 'a NON-tenant table owned by the runtime role, which proves migrations run as it' => [
            [
                'CREATE TABLE migrations_ran_as_runtime (note text)',
                \sprintf('ALTER TABLE migrations_ran_as_runtime OWNER TO %s', self::runtimeRole()),
            ],
            ['DROP TABLE migrations_ran_as_runtime'],
            'is OWNED by',
        ];
    }

    /** @return array{int, string} */
    private static function runGate(?string $runtimeRole = null, ?string $database = null): array
    {
        $command = \sprintf(
            'TWES_SCHEMA_DSN=%s TWES_SCHEMA_USER=%s TWES_SCHEMA_PASSWORD=%s TWES_SCHEMA_RUNTIME_ROLE=%s php %s 2>&1',
            escapeshellarg(\sprintf('pgsql:host=%s;port=%s;dbname=%s', self::host(), self::port(), $database ?? self::DATABASE)),
            escapeshellarg(self::superuserName()),
            escapeshellarg(self::superuserPassword()),
            escapeshellarg($runtimeRole ?? self::runtimeRole()),
            escapeshellarg(\dirname(__DIR__, 4) . '/scripts/gates/schema-tenancy.php'),
        );
        exec($command, $output, $status);

        return [$status, implode("\n", $output)];
    }

    private static function admin(): \PDO
    {
        return self::$admin ??= new \PDO(
            \sprintf('pgsql:host=%s;port=%s;dbname=%s', self::host(), self::port(), self::DATABASE),
            self::superuserName(),
            self::superuserPassword(),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    private static function superuser(): \PDO
    {
        return new \PDO(
            \sprintf('pgsql:host=%s;port=%s;dbname=postgres', self::host(), self::port()),
            self::superuserName(),
            self::superuserPassword(),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    private static function ownerUrl(): string
    {
        return \sprintf(
            'postgresql://%s:%s@%s:%s/%s?serverVersion=18&charset=utf8',
            self::ownerRole(),
            getenv('TWES_TEST_DB_OWNER_PASSWORD') ?: 'twes_owner',
            self::host(),
            self::port(),
            self::DATABASE,
        );
    }

    private static function host(): string
    {
        return '127.0.0.1';
    }

    private static function port(): string
    {
        return '5432';
    }

    private static function ownerRole(): string
    {
        return getenv('TWES_TEST_DB_OWNER_USER') ?: 'twes_owner';
    }

    private static function runtimeRole(): string
    {
        return getenv('TWES_TEST_DB_USER') ?: 'twes';
    }

    /**
     * The NOLOGIN probe role that `provision-test-database.sh` grants to the runtime role `WITH INHERIT FALSE`.
     *
     * Held but not inherited is the ONLY shape under which `has_table_privilege` and `SET ROLE` disagree, so this
     * role is what makes the two reachability cases above able to fail at all.
     */
    private static function truncatorRole(): string
    {
        return getenv('TWES_TEST_DB_TRUNCATOR_ROLE') ?: 'twes_truncator';
    }

    private static function superuserName(): string
    {
        $name = getenv('TWES_TEST_DB_SUPERUSER');

        if (!\is_string($name) || '' === $name) {
            self::fail(
                'TWES_TEST_DB_SUPERUSER must be set: this test creates and drops a database and mutates table '
                . 'ownership, which needs a superuser. It FAILS rather than skipping for the reason the rest of '
                . 'this suite does — a skipped run reports OK while the gate that guards every tenant table goes '
                . 'unexercised.',
            );
        }

        return $name;
    }

    private static function superuserPassword(): string
    {
        $password = getenv('TWES_TEST_DB_SUPERUSER_PASSWORD');

        return \is_string($password) ? $password : '';
    }
}
