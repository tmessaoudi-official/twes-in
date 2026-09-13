<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Application;

use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldType;

/** A custom field as it is written; on a revision the entity, key and type must say what the field already says. */
final readonly class CustomFieldInput
{
    /** @param list<string> $choices */
    public function __construct(
        public CustomFieldEntity $entity,
        public string $key,
        public string $label,
        public CustomFieldType $type,
        public bool $required,
        public array $choices,
        public int $sortOrder,
        public bool $isActive,
    ) {
    }
}
