<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Infrastructure\Doctrine\DoctrineCustomerRepository;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Infrastructure\Doctrine\DoctrineInvoiceRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Infrastructure\Doctrine\DoctrineProductRepository;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Infrastructure\Doctrine\DoctrineVendorRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lists at scale (docs/SPEC.md § 7): searching a list reads its trigram index, not the whole table. An index is used
 * only when the query repeats its expression exactly, and nothing else fails when the two drift apart: the search
 * still answers, one full scan at a time.
 */
final class SearchIndexesTest extends KernelTestCase
{
    /** @return iterable<string, array{class-string, string, string, string}> the entity, its alias, the condition, the index */
    public static function searches(): iterable
    {
        yield 'customers' => [Customer::class, 'c', DoctrineCustomerRepository::MATCHES_WORDS, 'idx_customer_search'];
        yield 'products' => [Product::class, 'p', DoctrineProductRepository::MATCHES_WORDS, 'idx_product_search'];
        yield 'vendors' => [Vendor::class, 'v', DoctrineVendorRepository::MATCHES_WORDS, 'idx_vendor_search'];
        yield 'invoices' => [Invoice::class, 'i', DoctrineInvoiceRepository::MATCHES_WORDS, 'idx_invoice_search'];
    }

    /** @param class-string $entity */
    #[DataProvider('searches')]
    public function testTheSearchConditionIsAnsweredByItsTrigramIndex(string $entity, string $alias, string $condition, string $index): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sql = $em->createQueryBuilder()->select("$alias.id")->from($entity, $alias)
            ->where($condition)
            ->getQuery()->getSQL();
        self::assertIsString($sql);
        $connection = $em->getConnection();

        $connection->beginTransaction();
        try {
            // A test table is tiny, and PostgreSQL rightly prefers reading it whole; forbid that to see what it can use.
            $connection->executeStatement('SET LOCAL enable_seqscan = off');
            $plan = '';
            foreach ($connection->fetchFirstColumn('EXPLAIN '.str_replace('?', "'carthage'", $sql)) as $line) {
                self::assertIsString($line);
                $plan .= $line."\n";
            }
        } finally {
            $connection->rollBack();
        }

        self::assertStringContainsString($index, $plan, $plan);
    }
}
