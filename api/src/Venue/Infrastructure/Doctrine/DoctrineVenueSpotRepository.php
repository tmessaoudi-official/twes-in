<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Infrastructure\Doctrine;

use App\Venue\Domain\VenueSpot;
use App\Venue\Domain\VenueSpotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineVenueSpotRepository implements VenueSpotRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofArea(Uuid $areaId): array
    {
        return $this->entityManager->getRepository(VenueSpot::class)->findBy(['area' => $areaId], ['id' => 'ASC']);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?VenueSpot
    {
        $spot = $this->entityManager->find(VenueSpot::class, $id);

        return null !== $spot && $spot->getCompany()->getId()->equals($companyId) ? $spot : null;
    }

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        return [] === $ids ? [] : $this->entityManager->getRepository(VenueSpot::class)->findBy(['id' => $ids, 'company' => $companyId]);
    }

    public function save(VenueSpot $spot): void
    {
        $this->entityManager->persist($spot);
        $this->entityManager->flush();
    }

    public function remove(VenueSpot $spot): void
    {
        $this->entityManager->remove($spot);
        $this->entityManager->flush();
    }
}
