<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Watch;

use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use App\Module\Invoices\Infrastructure\Watch\InvoiceWatch;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lists at scale (docs/SPEC.md § 7): the home counts the late customers on every load, and at a million invoices a
 * company's open ones are a small part of the table. The partial index holds only those, so the count reads 28 MB of
 * it instead of 1.7 GB of the table (378 ms to 190 ms). A partial index serves a query only when its predicate is
 * implied by the query's, and nothing else fails when the two drift apart: the count still answers, from a full scan.
 */
final class OpenInvoiceIndexTest extends KernelTestCase
{
    public function testTheLateCustomersCountIsAnsweredByTheOpenInvoiceIndex(): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $connection->beginTransaction();
        try {
            // A test table is tiny, and PostgreSQL rightly prefers reading it whole: forbid that. It then picks whichever
            // index is cheapest on an empty table, which says nothing about the one under test, so every other index
            // that can be dropped is dropped inside this transaction, which rolls back. Only an index whose predicate
            // the query implies can then answer it.
            $connection->executeStatement('SET LOCAL enable_seqscan = off');
            foreach ($connection->fetchFirstColumn(
                "SELECT c.relname FROM pg_index x JOIN pg_class c ON c.oid = x.indexrelid
                  WHERE x.indrelid = 'invoice'::regclass AND NOT x.indisprimary AND NOT x.indisunique AND c.relname <> 'idx_invoice_open_due'",
            ) as $other) {
                self::assertIsString($other);
                $connection->executeStatement('DROP INDEX '.$other);
            }
            $plan = '';
            foreach ($connection->fetchFirstColumn(
                'EXPLAIN SELECT i.customer_id FROM invoice i WHERE '.InvoiceWatch::LATE_WHERE.' GROUP BY i.customer_id',
                [
                    'company' => '01a0ecf8-8931-7d54-9164-5792991ac309',
                    'type' => InvoiceType::Invoice->value,
                    'statuses' => [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value],
                    'cutoff' => '2026-09-01',
                ],
                ['statuses' => ArrayParameterType::STRING],
            ) as $line) {
                self::assertIsString($line);
                $plan .= $line."\n";
            }
        } finally {
            $connection->rollBack();
        }

        self::assertStringContainsString('idx_invoice_open_due', $plan, $plan);
    }
}
