<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldDefinitionRepository;
use App\CustomFields\Domain\CustomFieldEntity;
use Symfony\Component\Uid\Uuid;

final class InMemoryCustomFieldDefinitions implements CustomFieldDefinitionRepository
{
    /** @var list<CustomFieldDefinition> */
    public array $fields = [];

    public function ofCompanyAndEntity(Uuid $companyId, CustomFieldEntity $entity): array
    {
        // One kind of record today; the comparison is what keeps products' and documents' fields apart when they come.
        // @phpstan-ignore identical.alwaysTrue
        $mine = array_values(array_filter($this->fields, static fn (CustomFieldDefinition $f) => $f->getCompany()->getId()->equals($companyId) && $f->getEntity() === $entity));
        usort($mine, static fn (CustomFieldDefinition $a, CustomFieldDefinition $b) => [$a->getSortOrder(), $a->getKey()] <=> [$b->getSortOrder(), $b->getKey()]);

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?CustomFieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->getId()->equals($id) && $field->getCompany()->getId()->equals($companyId)) {
                return $field;
            }
        }

        return null;
    }

    public function ofKey(Uuid $companyId, CustomFieldEntity $entity, string $key): ?CustomFieldDefinition
    {
        foreach ($this->ofCompanyAndEntity($companyId, $entity) as $field) {
            if ($field->getKey() === $key) {
                return $field;
            }
        }

        return null;
    }

    public function save(CustomFieldDefinition $field): void
    {
        if (!\in_array($field, $this->fields, true)) {
            $this->fields[] = $field;
        }
    }
}
