<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/** One of the company's active units, as a product may be sold in it. */
final readonly class ProductUnitOption
{
    public function __construct(
        #[Groups([ProductOptionsResource::READ])]
        public string $id,
        #[Groups([ProductOptionsResource::READ])]
        public string $code,
        #[Groups([ProductOptionsResource::READ])]
        public string $name,
        /** How many decimals a quantity in this unit takes. */
        #[Groups([ProductOptionsResource::READ])]
        public int $decimals,
    ) {
    }
}
