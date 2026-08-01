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
    // The probe-database machinery, shared with `BehaviouralIsolationTest` so the `DATABASE_URL_OWNER` lesson
    // exists in ONE place. A second hand-written copy is how it gets re-learned.
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_schema_gate_probe';

    private static ?\PDO $admin = null;

    public static function setUpBeforeClass(): void
    {
        self::createMigratedProbeDatabase(self::DATABASE);
    }

    public static function tearDownAfterClass(): void
    {
        self::dropProbeDatabase(self::DATABASE);
        self::$admin = null;
    }

    public function testTheGateAcceptsTheSchemaOurMigrationProduces(): void
    {
        [$status, $output] = self::runGate();

        self::assertSame(0, $status, "The gate must accept our own migration's output:\n" . $output);
        self::assertStringContainsString('tenant-owned relation(s)', $output);
        // The OK message must claim ONLY what the gate still checks. It used to say "enabled, FORCED, canonically
        // policed, NOT NULL, and beyond ownership and TRUNCATE"; five of those moved to BehaviouralIsolationTest,
        // and a success message that overclaims is one the next reader stops believing.
        self::assertStringNotContainsString('FORCED', $output);
        self::assertStringNotContainsString('TRUNCATE', $output);
        self::assertStringContainsString('BehaviouralIsolationTest', $output);
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
        $superuser = self::superuserConnection();
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
     * A MATERIALIZED VIEW over tenant data must not be reported by THIS gate — and this case is what makes the
     * relkind scoping of the NOT NULL assertion load-bearing.
     *
     * `pg_attribute.attnotnull` is false on every matview, view and foreign-table column whatever the base table
     * declares, so applying the NOT NULL assertion to them would report a violation on every correct matview in the
     * schema. `NOT_NULL_CAPABLE_RELKINDS` scopes it, and without a case the scoping could be deleted with the whole
     * suite green — the "declared but unconsulted" shape `CLAUDE.md` § Gotchas records for
     * `PERMISSIVE_FOR_FONT_ASSETS`.
     *
     * A matview holding tenant data IS a real defect: it can carry no policy at all, so it is an unpoliced copy by
     * construction. It is reported by `BehaviouralIsolationTest`, which reads another tenant's rows out of it —
     * stronger evidence than a catalogue flag, and a message naming the actual consequence. This case asserts the
     * SPLIT rather than the absence of the check.
     */
    public function testTheGateDoesNotReportOnAMaterializedViewWhoseColumnsCannotCarryNotNull(): void
    {
        $admin = self::admin();
        $admin->exec('CREATE MATERIALIZED VIEW tenant_snapshot AS SELECT company_id, id FROM document');

        try {
            [$status, $output] = self::runGate();

            self::assertSame(
                0,
                $status,
                "A matview's columns never carry NOT NULL, so an unscoped assertion would refuse this correct "
                . "schema. The matview is the behavioural suite's finding, not this gate's:\n" . $output,
            );
            // Counted as tenant-owned -- discovery still finds it, which is what makes the behavioural suite
            // attack it. Only the NOT NULL assertion is scoped away.
            self::assertStringContainsString('tenant_owned=5', $output);
            self::assertStringNotContainsString('NULLABLE', $output);
        } finally {
            $admin->exec('DROP MATERIALIZED VIEW IF EXISTS tenant_snapshot');
        }
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

        // Still here, but now pointed at a RETAINED axis. Its expectation used to be `has NO row-level security`,
        // which moved to the behavioural suite -- so the case would have been deleted along with that axis and the
        // property it really guards would have gone with it. What it actually proves is that the scope is EVERY
        // non-system schema rather than `public`: the old narrowing made this gate narrower than the runtime
        // checker it backstops, so a tenant table in `reporting` was invisible to both. A nullable tenant column
        // in another schema exercises the same scope against an assertion this gate still makes.
        yield 'a tenant table in another schema, which the public-only scope never saw' => [
            [
                \sprintf('CREATE SCHEMA reporting AUTHORIZATION %s', self::ownerRole()),
                'CREATE TABLE reporting.archive (company_id uuid, id uuid NOT NULL)',
            ],
            ['DROP SCHEMA reporting CASCADE'],
            'reporting.archive.company_id is NULLABLE',
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

        // ---------------------------------------------------------------- polcmd and polroles
        //
        // A policy can be canonical in both halves and still guard nothing, because the gate read neither which
        // COMMANDS it covers nor which ROLES it applies to. Both fail CLOSED -- the runtime role is denied every
        // row rather than shown another tenant's -- so these are not breaches. They are the gate printing
        // "canonically policed" about a table that is in fact unusable, which is its own defect: a control whose
        // OK sentence is untrue trains the reader to stop believing it.

        yield 'a table this gate cannot classify' => [
            ['CREATE TABLE ambiguous (tenant_id uuid NOT NULL, note text)'],
            ['DROP TABLE ambiguous'],
            // Asserted on the CONSEQUENCE rather than on the wording, which is the part that matters now that this
            // gate's discovery is what the behavioural suite attacks: an unclassified relation is not attacked at
            // all, so a silent skip here would be a hole in both checks at once.
            'goes UNATTACKED',
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
        return self::$admin ??= self::connectionTo(
            self::DATABASE,
            self::superuserName(),
            self::superuserPassword(),
        );
    }

    /** The fixture's BYPASSRLS role. A view it owns reads the base table with row security not applied. */
    private static function bypassRole(): string
    {
        return getenv('TWES_TEST_DB_BYPASS_USER') ?: 'twes_bypass';
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
}
