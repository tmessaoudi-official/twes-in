<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * One tool of the structure layer, at the size THIS company builds at — `VenuePlanSettings` holds the sizes, and
 * this is what they look like once the chain has been walked for the company acting.
 *
 * A height travels with it, which the stock palette's shapes have none of: a rack's height is a fact about the rack
 * somebody measures, while a wall's is the same for every wall of the building until the company says otherwise.
 */
final readonly class StockStructureShapeOption
{
    public function __construct(
        /** Which tool it is: `wall`, `door`, `post` or `dock`, which is also how the screen names and draws it. */
        #[Groups([StockOptionsResource::READ])]
        public string $kind,
        #[Groups([StockOptionsResource::READ])]
        public string $width,
        #[Groups([StockOptionsResource::READ])]
        public string $depth,
        #[Groups([StockOptionsResource::READ])]
        public string $height,
    ) {
    }
}
