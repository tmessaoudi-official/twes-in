<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Doctrine;

use App\Fiscal\Domain\Unit;
use App\Fiscal\Domain\UnitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUnitRepository implements UnitRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(Unit::class)->findBy(['company' => $companyId], ['sortOrder' => 'ASC', 'code' => 'ASC']);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Unit
    {
        $unit = $this->entityManager->find(Unit::class, $id);

        return null !== $unit && $unit->getCompany()->getId()->equals($companyId) ? $unit : null;
    }

    public function ofCodeInCompany(string $code, Uuid $companyId): ?Unit
    {
        return $this->entityManager->getRepository(Unit::class)->findOneBy(['company' => $companyId, 'code' => $code]);
    }

    public function save(Unit $unit): void
    {
        $this->entityManager->persist($unit);
        $this->entityManager->flush();
    }
}
