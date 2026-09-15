<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/** An active product whose stock is kept, with the unit its stock is counted in. */
final readonly class StockProductOption
{
    public function __construct(
        #[Groups([StockOptionsResource::READ])]
        public string $id,
        #[Groups([StockOptionsResource::READ])]
        public string $reference,
        #[Groups([StockOptionsResource::READ])]
        public string $name,
        #[Groups([StockOptionsResource::READ])]
        public string $unitCode,
        /** How many decimals a quantity of it takes. */
        #[Groups([StockOptionsResource::READ])]
        public int $unitDecimals,
    ) {
    }
}
