<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Customers;

use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Infrastructure\Doctrine\DoctrineCustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lists at scale (docs/SPEC.md § 7): searching customers reads the trigram index, not the whole table. An index is
 * used only when the query repeats its expression exactly, and nothing else fails when the two drift apart: the search
 * still answers, one full scan at a time.
 */
final class CustomerSearchIndexTest extends KernelTestCase
{
    public function testTheSearchConditionIsAnsweredByTheTrigramIndex(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sql = $em->createQueryBuilder()->select('c.id')->from(Customer::class, 'c')
            ->where(DoctrineCustomerRepository::MATCHES_WORDS)
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

        self::assertStringContainsString('idx_customer_search', $plan, $plan);
    }
}
