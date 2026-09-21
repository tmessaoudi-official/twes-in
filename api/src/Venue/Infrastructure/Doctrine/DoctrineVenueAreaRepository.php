<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Infrastructure\Doctrine;

use App\Venue\Domain\VenueArea;
use App\Venue\Domain\VenueAreaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineVenueAreaRepository implements VenueAreaRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        /** @var list<VenueArea> $areas */
        $areas = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(VenueArea::class, 'a')
            ->join('a.establishment', 'e')
            ->where('a.company = :company')
            ->setParameter('company', $companyId)
            ->addOrderBy('e.code', 'ASC')
            ->addOrderBy('a.level', 'ASC')
            ->getQuery()
            ->getResult();

        return $areas;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?VenueArea
    {
        $area = $this->entityManager->find(VenueArea::class, $id);

        return null !== $area && $area->getCompany()->getId()->equals($companyId) ? $area : null;
    }

    public function ofLevelInEstablishment(int $level, Uuid $establishmentId): ?VenueArea
    {
        return $this->entityManager->getRepository(VenueArea::class)->findOneBy(['establishment' => $establishmentId, 'level' => $level]);
    }

    public function save(VenueArea $area): void
    {
        $this->entityManager->persist($area);
        $this->entityManager->flush();
    }

    public function remove(VenueArea $area): void
    {
        $this->entityManager->remove($area);
        $this->entityManager->flush();
    }
}
