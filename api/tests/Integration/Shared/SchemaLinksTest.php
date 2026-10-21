<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\DataHealth\Infrastructure\CompanyReferences;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Shared\Infrastructure\Doctrine\SchemaLinks;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the database says of its foreign keys, read once for every engine that follows them. A key over several columns
 * names a row by all of them together: read as its first column alone, it would show a link that is not there and hide
 * the one that is, so it is refused until someone says how it reads. The schema's own DDL runs inside the test's
 * transaction and goes with it.
 */
final class SchemaLinksTest extends KernelTestCase
{
    public function testEveryForeignKeyIsReadWithTheColumnThatNamesTheRow(): void
    {
        self::bootKernel();
        $keys = new SchemaLinks($this->connection())->foreignKeys();

        self::assertContains(['table' => 'invoice', 'column' => 'customer_id', 'target' => 'customer'], $keys);
    }

    public function testAForeignKeyOverSeveralColumnsIsRefusedNotReadAsItsFirst(): void
    {
        self::bootKernel();
        $this->compositeKey();

        $this->expectException(\UnexpectedValueException::class);
        new SchemaLinks($this->connection())->foreignKeys();
    }

    public function testTheCompanyCheckReadsTheSameKeysAndRefusesTheSame(): void
    {
        self::bootKernel();
        $this->compositeKey();

        $this->expectException(\UnexpectedValueException::class);
        static::getContainer()->get(CompanyReferences::class)->crossCompanyReferences();
    }

    private function compositeKey(): void
    {
        // The application's role may not change the schema, so the key is drawn in its own temporary schema, made the
        // current one until the test's transaction ends.
        $this->connection()->executeStatement('CREATE TEMPORARY TABLE schema_links_probe_target (company_id uuid NOT NULL, id uuid NOT NULL, PRIMARY KEY (company_id, id)) ON COMMIT DROP');
        $this->connection()->executeStatement('CREATE TEMPORARY TABLE schema_links_probe (id uuid PRIMARY KEY, company_id uuid NOT NULL, target_id uuid NOT NULL, FOREIGN KEY (company_id, target_id) REFERENCES schema_links_probe_target (company_id, id)) ON COMMIT DROP');
        $this->connection()->executeStatement('SET LOCAL search_path = pg_temp, public');
        self::assertStringStartsWith('pg_temp', Rows::text($this->connection()->fetchOne('SELECT current_schema()')));
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
