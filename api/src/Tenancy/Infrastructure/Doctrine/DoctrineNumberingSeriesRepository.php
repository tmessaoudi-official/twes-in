<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Tenancy\Domain\NumberingSeries;
use App\Tenancy\Domain\NumberingSeriesRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineNumberingSeriesRepository implements NumberingSeriesRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        /** @var list<NumberingSeries> $series */
        $series = $this->entityManager->createQueryBuilder()
            ->select('s', 'e')
            ->from(NumberingSeries::class, 's')
            ->join('s.establishment', 'e')
            ->where('s.company = :company')
            ->setParameter('company', $companyId, 'uuid')
            ->orderBy('e.code', 'ASC')
            ->addOrderBy('s.documentType', 'ASC')
            ->getQuery()
            ->getResult();

        return $series;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?NumberingSeries
    {
        $series = $this->entityManager->find(NumberingSeries::class, $id);

        return null !== $series && $series->getCompany()->getId()->equals($companyId) ? $series : null;
    }

    public function lockedDefaultFor(Uuid $establishmentId, string $documentType): ?NumberingSeries
    {
        /** @var NumberingSeries|null $series */
        $series = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(NumberingSeries::class, 's')
            ->where('s.establishment = :establishment')
            ->andWhere('s.documentType = :documentType')
            ->andWhere('s.isDefault = true')
            ->setParameter('establishment', $establishmentId, 'uuid')
            ->setParameter('documentType', $documentType)
            ->getQuery()
            // SELECT … FOR UPDATE, which Doctrine refuses outside a transaction; the refresh hint replaces whatever an
            // earlier read of the row in this request left in memory with what the lock just read.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $series;
    }

    public function save(NumberingSeries $series): void
    {
        $this->entityManager->persist($series);
        $this->entityManager->flush();
    }
}
