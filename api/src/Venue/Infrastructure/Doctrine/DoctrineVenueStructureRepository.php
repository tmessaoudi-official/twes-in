<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Infrastructure\Doctrine;

use App\Venue\Domain\VenueStructure;
use App\Venue\Domain\VenueStructureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineVenueStructureRepository implements VenueStructureRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofArea(Uuid $areaId): array
    {
        return $this->entityManager->getRepository(VenueStructure::class)->findBy(['area' => $areaId], ['id' => 'ASC']);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?VenueStructure
    {
        $structure = $this->entityManager->find(VenueStructure::class, $id);

        return null !== $structure && $structure->getCompany()->getId()->equals($companyId) ? $structure : null;
    }

    public function save(VenueStructure $structure): void
    {
        $this->entityManager->persist($structure);
        $this->entityManager->flush();
    }

    public function remove(VenueStructure $structure): void
    {
        $this->entityManager->remove($structure);
        $this->entityManager->flush();
    }
}
