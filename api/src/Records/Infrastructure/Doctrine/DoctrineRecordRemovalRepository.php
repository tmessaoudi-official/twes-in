<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Infrastructure\Doctrine;

use App\Records\Domain\Model\RecordRemoval;
use App\Records\Domain\Repository\RecordRemovalRepository;
use App\Records\Domain\ValueObject\RemovalState;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineRecordRemovalRepository implements RecordRemovalRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(RecordRemoval $removal): void
    {
        $this->entityManager->persist($removal);
        $this->entityManager->flush();
    }

    public function ofId(Uuid $companyId, Uuid $id): ?RecordRemoval
    {
        return $this->inCompany($this->entityManager->find(RecordRemoval::class, $id), $companyId);
    }

    public function lockedOfId(Uuid $companyId, Uuid $id): ?RecordRemoval
    {
        return $this->inCompany($this->entityManager->find(RecordRemoval::class, $id, LockMode::PESSIMISTIC_WRITE), $companyId);
    }

    public function pendingOf(Uuid $companyId, array $subjects): array
    {
        /** @var list<RecordRemoval> $removals */
        $removals = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(RecordRemoval::class, 'r')
            ->where('r.company = :company')
            ->andWhere('r.state = :pending')
            ->andWhere('r.subject IN (:subjects)')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('pending', RemovalState::Pending->value)
            ->setParameter('subjects', $subjects)
            ->orderBy('r.removedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $removals;
    }

    public function due(\DateTimeImmutable $now, ?Uuid $companyId = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id', 'IDENTITY(r.company) AS company')
            ->from(RecordRemoval::class, 'r')
            ->where('r.state = :pending')
            ->andWhere('r.effectiveAt <= :now')
            ->setParameter('pending', RemovalState::Pending->value)
            ->setParameter('now', $now, 'datetime_immutable')
            ->orderBy('r.effectiveAt');
        if (null !== $companyId) {
            $query->andWhere('r.company = :company')->setParameter('company', $companyId, 'uuid');
        }

        return array_values(array_map(static function (mixed $row): array {
            if (!\is_array($row) || !$row['id'] instanceof Uuid || !\is_string($row['company'])) {
                throw new \LogicException('A removal reads as its id and its company.');
            }

            return ['company' => Uuid::fromString($row['company']), 'id' => $row['id']];
        }, $query->getQuery()->getArrayResult()));
    }

    private function inCompany(?RecordRemoval $removal, Uuid $companyId): ?RecordRemoval
    {
        return null !== $removal && $removal->getCompany()->getId()->equals($companyId) ? $removal : null;
    }
}
