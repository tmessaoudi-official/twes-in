<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Doctrine;

use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineTaxComponentRepository implements TaxComponentRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(TaxComponent::class)->findBy(['company' => $companyId], ['sortOrder' => 'ASC', 'code' => 'ASC']);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?TaxComponent
    {
        $component = $this->entityManager->find(TaxComponent::class, $id);

        return null !== $component && $component->getCompany()->getId()->equals($companyId) ? $component : null;
    }

    public function ofCodeInCompany(string $code, Uuid $companyId): ?TaxComponent
    {
        return $this->entityManager->getRepository(TaxComponent::class)->findOneBy(['company' => $companyId, 'code' => $code]);
    }

    public function save(TaxComponent $component): void
    {
        $this->entityManager->persist($component);
        $this->entityManager->flush();
    }
}
