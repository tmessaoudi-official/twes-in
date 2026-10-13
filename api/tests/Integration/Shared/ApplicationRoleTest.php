<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The application reaches the database as a role of its own, not as the one that owns the schema. A statement it is
 * tricked into running can then read and write rows and nothing more: it cannot rewrite the journal, run a program on
 * the database server (whose environment holds the backups' keys), or change the schema. The suite itself runs on this
 * role, so what the application needs and what it holds are proven by the same tests.
 */
final class ApplicationRoleTest extends KernelTestCase
{
    private const string ROLE = 'twes_app';

    /** Only rows are written, never rewritten: what happened stays what was recorded. */
    private const string JOURNAL = 'audit_log';

    public function testTheApplicationIsItsOwnRoleAndNoSuperuser(): void
    {
        $role = $this->connection()->fetchAssociative('SELECT current_user AS name, rolsuper, rolcreaterole, rolcreatedb FROM pg_roles WHERE rolname = current_user');

        self::assertSame(['name' => self::ROLE, 'rolsuper' => false, 'rolcreaterole' => false, 'rolcreatedb' => false], $role);
    }

    public function testEveryTableIsReadAndWrittenAndTheJournalOnlyAddedTo(): void
    {
        /** @var list<string> $tables */
        $tables = $this->connection()->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name");
        self::assertGreaterThan(70, \count($tables), 'the schema is there');

        $wrong = [];
        foreach ($tables as $table) {
            $expected = ['SELECT' => true, 'INSERT' => true, 'UPDATE' => self::JOURNAL !== $table, 'DELETE' => self::JOURNAL !== $table, 'TRUNCATE' => false];
            foreach ($expected as $privilege => $held) {
                if ($held !== $this->connection()->fetchOne('SELECT has_table_privilege(current_user, ?, ?)', [$table, $privilege])) {
                    $wrong[] = \sprintf('%s %s %s', $table, $held ? 'lacks' : 'holds', $privilege);
                }
            }
        }

        self::assertSame([], $wrong);
    }

    public function testTheJournalCannotBeRewrittenNorEmptied(): void
    {
        foreach (['UPDATE audit_log SET action = action', 'DELETE FROM audit_log', 'TRUNCATE audit_log'] as $statement) {
            self::assertNotNull($this->refusal($statement), $statement);
        }
    }

    /** What a superuser could do with one statement, none of which a row-writing application needs. */
    public function testNothingButRowsIsWithinReach(): void
    {
        foreach ([
            "COPY (SELECT 1) TO PROGRAM 'true'",
            'CREATE TABLE intruder (id int)',
            'ALTER TABLE audit_log DISABLE TRIGGER ALL',
            'DROP TABLE audit_log',
            "CREATE ROLE intruder LOGIN PASSWORD 'x'",
            "SELECT pg_read_file('/etc/hostname')",
        ] as $statement) {
            self::assertNotNull($this->refusal($statement), $statement);
        }
    }

    /** Each probe in a savepoint of its own: a refused statement aborts the transaction it ran in. */
    private function refusal(string $statement): ?string
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->executeStatement($statement);

            return null;
        } catch (DbalException $refused) {
            return $refused->getMessage();
        } finally {
            $connection->rollBack();
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
