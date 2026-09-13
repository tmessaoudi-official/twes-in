<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Domain;

/** What a record's value for one custom field must be; a retired field is no longer asked for. */
final readonly class CustomFieldRule
{
    /** @param list<string> $choices */
    public function __construct(
        public string $key,
        public CustomFieldType $type,
        public bool $required,
        public array $choices = [],
        public bool $active = true,
    ) {
    }
}
