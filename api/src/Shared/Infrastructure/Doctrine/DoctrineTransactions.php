<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\RecordChanged;
use App\Shared\Application\Transactions;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * A transaction on the Doctrine connection. Not EntityManager::wrapInTransaction(), which closes the entity manager
 * when the work throws: a refused request still answers its error through that same entity manager. What the work
 * changed is said once the outermost unit has committed, and never after a rollback (`StagedUntilCommit`: to open
 * screens, and in the answer to whoever made the request). A versioned record someone else saved after it was checked
 * fails its `UPDATE … WHERE version`, and answers as RecordVersions does.
 */
final readonly class DoctrineTransactions implements Transactions
{
    /** @var list<StagedUntilCommit> */
    private array $staged;

    /** @param iterable<StagedUntilCommit> $staged */
    public function __construct(private EntityManagerInterface $entityManager, #[AutowireIterator(StagedUntilCommit::TAG)] iterable $staged)
    {
        $this->staged = array_values([...$staged]);
    }

    public function run(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        // A begin may write inside the transaction (ClaimedSubmission marks its claim done), so one that fails is rolled
        // back with the work, and only the levels that began are told so.
        $begun = [];
        try {
            foreach ($this->staged as $staged) {
                $staged->begin();
                $begun[] = $staged;
            }
            $result = $work();
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $failure) {
            foreach ($begun as $staged) {
                $staged->rollBack();
            }
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $failure instanceof OptimisticLockException ? new RecordChanged($failure) : $failure;
        }
        foreach ($this->staged as $staged) {
            $staged->commit();
        }

        return $result;
    }

    public function active(): bool
    {
        return $this->entityManager->getConnection()->isTransactionActive();
    }
}
