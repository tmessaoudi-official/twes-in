<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Serializer\Attribute\Groups;

/** A tax regime a customer may be under, with the tax families it does not charge. */
final readonly class CustomerRegimeOption
{
    /** @param list<string> $excludedFamilies */
    public function __construct(
        #[Groups([CustomerOptionsResource::READ])]
        public string $code,
        #[Groups([CustomerOptionsResource::READ])]
        public string $label,
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['vat', 'levy', 'stamp', 'withholding']]])]
        #[Groups([CustomerOptionsResource::READ])]
        public array $excludedFamilies,
    ) {
    }
}
