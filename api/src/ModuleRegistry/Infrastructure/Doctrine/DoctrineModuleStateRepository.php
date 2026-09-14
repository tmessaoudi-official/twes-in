<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\Doctrine;

use App\ModuleRegistry\Domain\ModuleState;
use App\ModuleRegistry\Domain\ModuleStateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineModuleStateRepository implements ModuleStateRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(ModuleState::class)->findBy(['company' => $companyId], ['key' => 'ASC']);
    }

    public function ofKeyInCompany(string $key, Uuid $companyId): ?ModuleState
    {
        return $this->entityManager->getRepository(ModuleState::class)->findOneBy(['company' => $companyId, 'key' => $key]);
    }

    public function save(ModuleState $state): void
    {
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }
}
