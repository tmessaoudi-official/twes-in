<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Domain;

use Symfony\Component\Uid\Uuid;

interface CustomFieldDefinitionRepository
{
    /** @return list<CustomFieldDefinition> one company's fields for one kind of record, by position then key */
    public function ofCompanyAndEntity(Uuid $companyId, CustomFieldEntity $entity): array;

    /** Null for a field that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?CustomFieldDefinition;

    public function ofKey(Uuid $companyId, CustomFieldEntity $entity, string $key): ?CustomFieldDefinition;

    public function save(CustomFieldDefinition $field): void;
}
