<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Serializer\Attribute\Groups;

/** One of the company's active taxes, as a customer's default taxes may name it. */
final readonly class CustomerTaxOption
{
    public function __construct(
        #[Groups([CustomerOptionsResource::READ])]
        public string $id,
        #[Groups([CustomerOptionsResource::READ])]
        public string $code,
        #[Groups([CustomerOptionsResource::READ])]
        public string $name,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['vat', 'levy', 'stamp', 'withholding']])]
        #[Groups([CustomerOptionsResource::READ])]
        public string $family,
    ) {
    }
}
