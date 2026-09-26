<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\Doctrine;

use App\ModuleRegistry\Domain\ModuleInterest;
use App\ModuleRegistry\Domain\ModuleInterestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `waiting()` reads every company's rows: it is called by the operator's platform endpoint and by the console, where
 * CompanyFilter is never switched on (it is only for a request acting for one company).
 */
final readonly class DoctrineModuleInterestRepository implements ModuleInterestRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function waitingOfCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(ModuleInterest::class)->findBy(['company' => $companyId, 'announcedAt' => null], ['key' => 'ASC']);
    }

    public function ofKeyInCompany(string $key, Uuid $companyId): ?ModuleInterest
    {
        return $this->entityManager->getRepository(ModuleInterest::class)->findOneBy(['company' => $companyId, 'key' => $key]);
    }

    public function waiting(): array
    {
        return $this->entityManager->getRepository(ModuleInterest::class)->findBy(['announcedAt' => null], ['createdAt' => 'ASC']);
    }

    public function save(ModuleInterest $interest): void
    {
        $this->entityManager->persist($interest);
        $this->entityManager->flush();
    }

    public function remove(ModuleInterest $interest): void
    {
        $this->entityManager->remove($interest);
        $this->entityManager->flush();
    }
}
