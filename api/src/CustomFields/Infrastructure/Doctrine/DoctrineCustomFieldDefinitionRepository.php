<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Infrastructure\Doctrine;

use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldDefinitionRepository;
use App\CustomFields\Domain\CustomFieldEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineCustomFieldDefinitionRepository implements CustomFieldDefinitionRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompanyAndEntity(Uuid $companyId, CustomFieldEntity $entity): array
    {
        return $this->entityManager->getRepository(CustomFieldDefinition::class)->findBy(['company' => $companyId, 'entity' => $entity], ['sortOrder' => 'ASC', 'key' => 'ASC']);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?CustomFieldDefinition
    {
        $field = $this->entityManager->find(CustomFieldDefinition::class, $id);

        return null !== $field && $field->getCompany()->getId()->equals($companyId) ? $field : null;
    }

    public function ofKey(Uuid $companyId, CustomFieldEntity $entity, string $key): ?CustomFieldDefinition
    {
        return $this->entityManager->getRepository(CustomFieldDefinition::class)->findOneBy(['company' => $companyId, 'entity' => $entity, 'key' => $key]);
    }

    public function save(CustomFieldDefinition $field): void
    {
        $this->entityManager->persist($field);
        $this->entityManager->flush();
    }
}
