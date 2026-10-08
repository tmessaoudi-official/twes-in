<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\StockLocation;

/** What one drawn place holds of a product, itself and the places under it together; no place is the undrawn rest. */
final readonly class MapHolding
{
    /** @param list<MapHoldingLine> $lines */
    public function __construct(
        public ?StockLocation $place,
        public string $quantity,
        public array $lines,
    ) {
    }
}
