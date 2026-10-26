<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Inventory\Domain\Model\StockMoveFailure;
use App\Module\Inventory\Domain\Repository\StockMoveFailureRepository;
use App\Module\Inventory\Domain\ValueObject\FailedMove;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineStockMoveFailureRepository implements StockMoveFailureRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofId(Uuid $id, Uuid $companyId): ?StockMoveFailure
    {
        return $this->entityManager->getRepository(StockMoveFailure::class)->findOneBy(['id' => $id, 'company' => $companyId]);
    }

    public function of(Uuid $companyId, FailedMove $move, Uuid $documentId): ?StockMoveFailure
    {
        return $this->entityManager->getRepository(StockMoveFailure::class)->findOneBy(['company' => $companyId, 'move' => $move, 'documentId' => $documentId]);
    }

    public function ofDocument(Uuid $companyId, Uuid $documentId): array
    {
        /** @var list<StockMoveFailure> $failures */
        $failures = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(StockMoveFailure::class, 'f')
            ->where('f.company = :company')
            ->andWhere('f.documentId = :document')
            ->orderBy('f.failedAt', 'ASC')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('document', $documentId, 'uuid')
            ->getQuery()
            ->getResult();

        return $failures;
    }

    public function save(StockMoveFailure $failure): void
    {
        $this->entityManager->persist($failure);
        $this->entityManager->flush();
    }

    public function remove(StockMoveFailure $failure): void
    {
        $this->entityManager->remove($failure);
        $this->entityManager->flush();
    }
}
