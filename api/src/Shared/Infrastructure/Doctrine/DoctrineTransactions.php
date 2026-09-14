<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\Transactions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A transaction on the Doctrine connection. Not EntityManager::wrapInTransaction(), which closes the entity manager
 * when the work throws: a refused request still answers its error through that same entity manager.
 */
final readonly class DoctrineTransactions implements Transactions
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function run(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    public function active(): bool
    {
        return $this->entityManager->getConnection()->isTransactionActive();
    }
}
