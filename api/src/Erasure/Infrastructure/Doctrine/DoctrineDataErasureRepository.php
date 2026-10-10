<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Infrastructure\Doctrine;

use App\Erasure\Domain\DataErasure;
use App\Erasure\Domain\DataErasureRepository;
use App\Erasure\Domain\ErasureState;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineDataErasureRepository implements DataErasureRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(DataErasure $erasure): void
    {
        $this->entityManager->persist($erasure);
        $this->entityManager->flush();
    }

    public function pendingOf(Uuid $companyId): ?DataErasure
    {
        return $this->pending($companyId, false);
    }

    public function lockedPendingOf(Uuid $companyId): ?DataErasure
    {
        return $this->pending($companyId, true);
    }

    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?DataErasure
    {
        $erasure = $this->lockedOfId($id);

        return null !== $erasure && $erasure->getCompany()->getId()->equals($companyId) ? $erasure : null;
    }

    public function dueAt(\DateTimeImmutable $now, ?Uuid $companyId = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('e.id')
            ->from(DataErasure::class, 'e')
            ->where('e.state = :pending')
            ->andWhere('e.effectiveAt <= :now')
            ->setParameter('pending', ErasureState::Pending->value)
            ->setParameter('now', $now, 'datetime_immutable')
            ->orderBy('e.effectiveAt');
        if (null !== $companyId) {
            $query->andWhere('e.company = :company')->setParameter('company', $companyId, 'uuid');
        }

        return array_values(array_map(static fn (mixed $row): Uuid => \is_array($row) && $row['id'] instanceof Uuid ? $row['id'] : throw new \LogicException('An erasure id reads as a Uuid.'), $query->getQuery()->getArrayResult()));
    }

    public function lockedOfId(Uuid $id): ?DataErasure
    {
        return $this->entityManager->find(DataErasure::class, $id, LockMode::PESSIMISTIC_WRITE);
    }

    private function pending(Uuid $companyId, bool $locked): ?DataErasure
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(DataErasure::class, 'e')
            ->where('e.company = :company')
            ->andWhere('e.state = :pending')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('pending', ErasureState::Pending->value)
            ->getQuery();
        if ($locked) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        /** @var DataErasure|null $erasure */
        $erasure = $query->getOneOrNullResult();

        return $erasure;
    }
}
