<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Serializer\Attribute\Groups;

/** One of the company's active taxes charged on a line, as a product's default taxes may name it. */
final readonly class ProductTaxOption
{
    public function __construct(
        #[Groups([ProductOptionsResource::READ])]
        public string $id,
        #[Groups([ProductOptionsResource::READ])]
        public string $code,
        #[Groups([ProductOptionsResource::READ])]
        public string $name,
        #[ApiProperty(schema: ['type' => 'string', 'enum' => ['vat', 'levy']])]
        #[Groups([ProductOptionsResource::READ])]
        public string $family,
    ) {
    }
}
