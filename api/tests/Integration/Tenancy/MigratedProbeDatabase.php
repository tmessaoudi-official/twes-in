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

/**
 * A throwaway database carrying the schema OUR migration produces, for tests that need a real one.
 *
 * **Extracted because the one lesson in here must not be re-learned, and a second hand-written copy is how it
 * would be.** `doctrine_migrations.yaml` pins migrations to the `owner` connection, so overriding `DATABASE_URL`
 * alone leaves the migration pointed at whatever `.env` names — the DEV database. That is not hypothetical: it is
 * what `SchemaTenancyGateTest` did for one commit, migrating `twes_in` (already up to date, so exit 0) while its
 * own probe database stayed empty and every case failed. A migration that exits 0 does not tell you WHICH database
 * it migrated, which is why {@see self::createMigratedProbeDatabase()} counts the tables it expects to find rather
 * than trusting the exit code.
 *
 * The migration is run by the console rather than reimplemented here, and that is the point: a test using this
 * trait asserts something about what our OWN migration produces, so a hand-built schema would be testing a
 * different thing and would drift the moment the migration changed.
 *
 * Each consumer passes its own database name. They must differ: the suite runs these classes in the same process
 * and a shared name would have one class dropping the other's database mid-test.
 */
trait MigratedProbeDatabase
{
    /**
     * Create `$database`, owned by the owning role, and run every migration into it.
     *
     * Owned by `ownerRole()` and never by the runtime role — that is the topology production must have, and a
     * fixture that cannot express it cannot detect its absence. `schema-tenancy.php` refuses a schema whose tables
     * the runtime role owns, so a probe database built the convenient way would fail the very gate it feeds.
     */
    protected static function createMigratedProbeDatabase(string $database): void
    {
        $superuser = self::superuserConnection();
        $superuser->exec(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $database));
        $superuser->exec(\sprintf('CREATE DATABASE %s OWNER %s', $database, self::ownerRole()));

        // BOTH url variables, and `DATABASE_URL_OWNER` is the load-bearing one -- see the class docblock.
        // `DATABASE_URL` is set as well so the default connection cannot reach a different database than the one
        // under test.
        $url = self::ownerUrlFor($database);
        $migrate = \sprintf(
            'cd %s && DATABASE_URL=%s DATABASE_URL_OWNER=%s php bin/console doctrine:migrations:migrate'
            . ' --no-interaction 2>&1',
            escapeshellarg(\dirname(__DIR__, 3)),
            escapeshellarg($url),
            escapeshellarg($url),
        );
        exec($migrate, $output, $status);

        \PHPUnit\Framework\Assert::assertSame(
            0,
            $status,
            "The migration must succeed before this test can assert anything:\n" . implode("\n", $output),
        );

        // Success against WHICH database? A run pointed at an already-migrated database also exits 0, which is
        // exactly how the failure in the class docblock stayed invisible at the one assertion that should have
        // caught it. So assert the tables are HERE.
        $present = self::connectionTo($database, self::superuserName(), self::superuserPassword())->query(
            'SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace'
            . " WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname LIKE 'document%'",
        );

        \PHPUnit\Framework\Assert::assertNotFalse($present, 'could not count the migrated tables');
        \PHPUnit\Framework\Assert::assertGreaterThanOrEqual(
            4,
            (int) $present->fetchColumn(),
            'The migration exited 0 but ' . $database . ' has no document tables, so it migrated a DIFFERENT one. '
            . 'Check that DATABASE_URL_OWNER is overridden: doctrine_migrations.yaml pins migrations to the '
            . '"owner" connection, so overriding DATABASE_URL alone silently targets whatever .env names.',
        );
    }

    protected static function dropProbeDatabase(string $database): void
    {
        self::superuserConnection()->exec(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $database));
    }

    /** A connection to the maintenance database, for CREATE/DROP DATABASE. */
    protected static function superuserConnection(): \PDO
    {
        return self::connectionTo('postgres', self::superuserName(), self::superuserPassword());
    }

    protected static function connectionTo(
        string $database,
        string $user,
        string $password,
        string $extraDsn = '',
    ): \PDO {
        return new \PDO(
            \sprintf(
                'pgsql:host=%s;port=%s;dbname=%s%s',
                self::host(),
                self::port(),
                $database,
                '' === $extraDsn ? '' : ';' . $extraDsn,
            ),
            $user,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    protected static function ownerUrlFor(string $database): string
    {
        return \sprintf(
            'postgresql://%s:%s@%s:%s/%s?serverVersion=18&charset=utf8',
            self::ownerRole(),
            getenv('TWES_TEST_DB_OWNER_PASSWORD') ?: 'twes_owner',
            self::host(),
            self::port(),
            $database,
        );
    }

    protected static function host(): string
    {
        return '127.0.0.1';
    }

    protected static function port(): string
    {
        return '5432';
    }

    /** The role that OWNS the tenant tables, and which is never granted to the runtime role. */
    protected static function ownerRole(): string
    {
        return getenv('TWES_TEST_DB_OWNER_USER') ?: 'twes_owner';
    }

    protected static function ownerPassword(): string
    {
        return getenv('TWES_TEST_DB_OWNER_PASSWORD') ?: 'twes_owner';
    }

    /** The restricted role the application connects as: owns nothing, holds no TRUNCATE, cannot bypass. */
    protected static function runtimeRole(): string
    {
        return getenv('TWES_TEST_DB_USER') ?: 'twes';
    }

    protected static function runtimePassword(): string
    {
        return getenv('TWES_TEST_DB_PASSWORD') ?: 'twes';
    }

    protected static function superuserName(): string
    {
        $name = getenv('TWES_TEST_DB_SUPERUSER');

        if (!\is_string($name) || '' === $name) {
            \PHPUnit\Framework\Assert::fail(
                'TWES_TEST_DB_SUPERUSER must be set: this test creates and drops a database and builds privileged '
                . 'fixtures, which needs a superuser. It FAILS rather than skipping for the reason the rest of this '
                . 'suite does — a skipped run reports OK while the controls that guard every tenant table go '
                . 'unexercised.',
            );
        }

        return $name;
    }

    protected static function superuserPassword(): string
    {
        $password = getenv('TWES_TEST_DB_SUPERUSER_PASSWORD');

        return \is_string($password) ? $password : '';
    }
}
