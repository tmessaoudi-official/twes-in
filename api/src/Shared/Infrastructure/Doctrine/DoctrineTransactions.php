<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\RecordChanged;
use App\Shared\Application\Transactions;
use App\Shared\Infrastructure\Realtime\StagedLiveChanges;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/**
 * A transaction on the Doctrine connection. Not EntityManager::wrapInTransaction(), which closes the entity manager
 * when the work throws: a refused request still answers its error through that same entity manager. What the work
 * changed is said to open screens once the outermost unit has committed, and never after a rollback. A versioned record
 * someone else saved after it was checked fails its `UPDATE … WHERE version`, and answers as RecordVersions does.
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
            throw $failure instanceof OptimisticLockException ? new RecordChanged($failure) : $failure;
        }
        $this->liveChanges->commit();

        return $result;
    }

    public function active(): bool
    {
        return $this->entityManager->getConnection()->isTransactionActive();
    }
}
