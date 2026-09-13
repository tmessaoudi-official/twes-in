<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineEstablishmentRepository implements EstablishmentRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(Establishment::class)->findBy(['company' => $companyId], ['isDefault' => 'DESC', 'code' => 'ASC']);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Establishment
    {
        $establishment = $this->entityManager->find(Establishment::class, $id);

        return null !== $establishment && $establishment->getCompany()->getId()->equals($companyId) ? $establishment : null;
    }

    public function ofCodeInCompany(string $code, Uuid $companyId): ?Establishment
    {
        return $this->entityManager->getRepository(Establishment::class)->findOneBy(['company' => $companyId, 'code' => $code]);
    }

    public function save(Establishment $establishment): void
    {
        $this->entityManager->persist($establishment);
        $this->entityManager->flush();
    }
}
