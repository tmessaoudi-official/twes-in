<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\Transactions;
use App\Shared\Infrastructure\Realtime\StagedLiveChanges;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A transaction on the Doctrine connection. Not EntityManager::wrapInTransaction(), which closes the entity manager
 * when the work throws: a refused request still answers its error through that same entity manager. What the work
 * changed is said to open screens once the outermost unit has committed, and never after a rollback.
 */
final readonly class DoctrineTransactions implements Transactions
{
    public function __construct(private EntityManagerInterface $entityManager, private StagedLiveChanges $liveChanges)
    {
    }

    public function run(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $this->liveChanges->begin();
        try {
            $result = $work();
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $failure) {
            $this->liveChanges->rollBack();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $failure;
        }
        $this->liveChanges->commit();

        return $result;
    }

    public function active(): bool
    {
        return $this->entityManager->getConnection()->isTransactionActive();
    }
}
