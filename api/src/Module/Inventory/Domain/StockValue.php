<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use Symfony\Component\Uid\Uuid;

/** What the stock of one product is worth: its quantity, the value of the part that has a cost, and the part that has none. */
final readonly class StockValue
{
    /**
     * @param numeric-string $quantity
     * @param numeric-string $value            seven decimals as summed, rounded by whoever shows it
     * @param numeric-string $unvaluedQuantity
     */
    public function __construct(
        public Uuid $productId,
        public string $quantity,
        public string $value,
        public string $unvaluedQuantity,
    ) {
    }
}
