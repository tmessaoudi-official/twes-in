<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Infrastructure\Doctrine\DoctrineCustomerRepository;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Infrastructure\Doctrine\DoctrineDeliveryNoteRepository;
use App\Module\Expenses\Domain\Model\Expense;
use App\Module\Expenses\Infrastructure\Doctrine\DoctrineExpenseRepository;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Infrastructure\Doctrine\DoctrineInvoiceRepository;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Infrastructure\Doctrine\DoctrineProductRepository;
use App\Module\Quotes\Domain\Model\Quote;
use App\Module\Quotes\Infrastructure\Doctrine\DoctrineQuoteRepository;
use App\Module\Vendors\Domain\Model\Vendor;
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
        yield 'expenses' => [Expense::class, 'e', DoctrineExpenseRepository::MATCHES_WORDS, 'idx_expense_search'];
        yield 'delivery notes' => [DeliveryNote::class, 'n', DoctrineDeliveryNoteRepository::MATCHES_WORDS, 'idx_delivery_note_search'];
        yield 'quotes' => [Quote::class, 'q', DoctrineQuoteRepository::MATCHES_WORDS, 'idx_quote_search'];
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

    /**
     * An import asks, for each new product, whether another already holds its name: with the company's whole catalogue
     * read for every row, a file of 2000 rows into a shop of 20,000 products reads the catalogue 2000 times. The index
     * serves the lookup only while the query repeats its expression. On a test's empty table this index and one on the
     * company cost the planner the same, so its choice proves nothing: the index and the query are compared instead.
     */
    public function testAProductsNameIsFoundThroughItsIndex(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sql = $em->createQueryBuilder()->select('p.id')->from(Product::class, 'p')
            ->where('p.company = :company')->andWhere(DoctrineProductRepository::NAMED)
            ->getQuery()->getSQL();
        self::assertIsString($sql);

        $definition = $em->getConnection()->fetchOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'product' AND indexname = 'idx_product_name'");
        self::assertSame('CREATE INDEX idx_product_name ON public.product USING btree (lower((name)::text))', $definition);
        self::assertMatchesRegularExpression('/\b(\w+)\.company_id = \? AND LOWER\(\1\.name\) = LOWER\(\?\)/', $sql);
    }
}
