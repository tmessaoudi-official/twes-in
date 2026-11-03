<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Module\Invoices\Application\Credit\CreditAccountsHeld;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A transaction-scoped advisory lock: an account is a sum of documents and payments, so there is no one row to lock, and
 * the customer's own row belongs to the customers. Its key has a prefix of its own, never a stock key's.
 */
final readonly class AdvisoryCreditAccountsHeld implements CreditAccountsHeld
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function hold(Uuid $companyId, Uuid $keptCustomerId): void
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('A credit account is held inside the transaction that weighs it.');
        }
        $connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['credit-account:'.$companyId->toRfc4122().':'.$keptCustomerId->toRfc4122()],
        );
    }
}
