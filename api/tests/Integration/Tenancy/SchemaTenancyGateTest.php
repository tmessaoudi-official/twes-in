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
            'is OWNED by the runtime role',
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
        yield 'a NON-tenant table owned by the runtime role, which proves migrations run as it' => [
            [
                'CREATE TABLE migrations_ran_as_runtime (note text)',
                \sprintf('ALTER TABLE migrations_ran_as_runtime OWNER TO %s', self::runtimeRole()),
            ],
            ['DROP TABLE migrations_ran_as_runtime'],
            'is OWNED by the runtime role',
        ];
    }

    /** @return array{int, string} */
    private static function runGate(): array
    {
        $command = \sprintf(
            'TWES_SCHEMA_DSN=%s TWES_SCHEMA_USER=%s TWES_SCHEMA_PASSWORD=%s TWES_SCHEMA_RUNTIME_ROLE=%s php %s 2>&1',
            escapeshellarg(\sprintf('pgsql:host=%s;port=%s;dbname=%s', self::host(), self::port(), self::DATABASE)),
            escapeshellarg(self::superuserName()),
            escapeshellarg(self::superuserPassword()),
            escapeshellarg(self::runtimeRole()),
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
