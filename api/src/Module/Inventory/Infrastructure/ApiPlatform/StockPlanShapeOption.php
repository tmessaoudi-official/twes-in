<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * One ready-made shape of the plan's palette, at the size THIS company says it is — `VenuePlanSettings` holds the
 * sizes, and this is what they look like once the chain has been walked for the company acting.
 */
final readonly class StockPlanShapeOption
{
    public function __construct(
        /** Which shape it is: `rack`, `zone`, `aisle` or `dock`, which is also how the screen names and colours it. */
        #[Groups([StockOptionsResource::READ])]
        public string $shape,
        #[Groups([StockOptionsResource::READ])]
        public string $width,
        #[Groups([StockOptionsResource::READ])]
        public string $depth,
    ) {
    }
}
