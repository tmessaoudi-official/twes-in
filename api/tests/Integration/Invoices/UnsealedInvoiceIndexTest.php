<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Invoices;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every issue asks its series for documents not chained yet while it holds the series' lock, so every other issuer of
 * that series waits on the answer. Almost always there are none: the partial index holds only those, and answers
 * without reading the series. Without it the question walks every document the series ever issued, which at a
 * million invoices is felt on every issue, and nothing fails: the answer is the same, only slow. The query is the one
 * `DoctrineInvoiceChainStore::unsealed` sends, written out in SQL.
 */
final class UnsealedInvoiceIndexTest extends KernelTestCase
{
    public function testTheDocumentsNotChainedYetAreFoundThroughTheirOwnIndex(): void
    {
        self::bootKernel();
        // The schema's owner, who alone may drop an index; the application's role may not.
        $connection = static::getContainer()->get('doctrine.dbal.owner_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $connection->beginTransaction();
        try {
            // As OpenInvoiceIndexTest does: a test table is tiny, so reading it whole is forbidden, and every other
            // index is dropped inside this transaction, which rolls back, so only an index the query fits can answer.
            $connection->executeStatement('SET LOCAL enable_seqscan = off');
            foreach ($connection->fetchFirstColumn(
                "SELECT c.relname FROM pg_index x JOIN pg_class c ON c.oid = x.indexrelid
                  WHERE x.indrelid = 'invoice'::regclass AND NOT x.indisprimary AND c.relname <> 'idx_invoice_unsealed'",
            ) as $other) {
                self::assertIsString($other);
                $connection->executeStatement('DROP INDEX '.$other);
            }
            $plan = '';
            foreach ($connection->fetchFirstColumn(
                'EXPLAIN SELECT id FROM invoice WHERE company_id = :company AND establishment_id = :establishment AND document_type = :type
                   AND number IS NOT NULL AND chain_hash IS NULL ORDER BY issue_date, LENGTH(number), number, id LIMIT 500',
                ['company' => '01a0ecf8-8931-7d54-9164-5792991ac309', 'establishment' => '01a0ecf8-8931-7d54-9164-5792991ac30a', 'type' => 'invoice'],
            ) as $line) {
                self::assertIsString($line);
                $plan .= $line."\n";
            }
        } finally {
            $connection->rollBack();
        }

        self::assertStringContainsString('idx_invoice_unsealed', $plan, $plan);
    }
}
