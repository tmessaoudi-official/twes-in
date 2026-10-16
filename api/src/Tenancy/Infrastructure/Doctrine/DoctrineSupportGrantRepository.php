<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Tenancy\Domain\Model\SupportGrant;
use App\Tenancy\Domain\Repository\SupportGrantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSupportGrantRepository implements SupportGrantRepository
{
    private const string LIVE = 'g.endedAt IS NULL AND g.startsAt <= :now AND g.endsAt > :now';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function liveIn(Uuid $companyId, \DateTimeImmutable $now): ?SupportGrant
    {
        /** @var list<SupportGrant> $live */
        $live = $this->entityManager->createQuery('SELECT g FROM '.SupportGrant::class.' g WHERE g.company = :company AND '.self::LIVE.' ORDER BY g.endsAt DESC')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setMaxResults(1)
            ->getResult();

        return $live[0] ?? null;
    }

    public function liveAt(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<SupportGrant> $live */
        $live = $this->entityManager->createQuery('SELECT g, c FROM '.SupportGrant::class.' g JOIN g.company c WHERE '.self::LIVE.' ORDER BY c.name ASC, c.id ASC')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setMaxResults($limit)
            ->getResult();

        return $live;
    }

    public function save(SupportGrant $grant): void
    {
        $this->entityManager->persist($grant);
        $this->entityManager->flush();
    }
}
