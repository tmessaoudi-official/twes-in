<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\ValueObject\NamedLot;
use Symfony\Component\Uid\Uuid;

/**
 * One line of goods that arrived against an order to a vendor: the receipt line it comes from, which its movement names
 * as its source, the product, the place, the quantity, the lot on the goods, and what one unit cost when it is known;
 * `$costToComplete` when the person receiving may not read costs and nobody gave one.
 */
final readonly class OrderedGoods
{
    public function __construct(
        public Uuid $sourceId,
        public Uuid $productId,
        public Uuid $locationId,
        public string $quantity,
        public ?NamedLot $lot,
        public ?string $unitCost,
        public bool $costToComplete,
    ) {
    }
}
