<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\StockMap;

use App\Module\Inventory\Domain\Model\StockLocation;

/** The quantity of a product at one place, its lots together. */
final readonly class MapHoldingLine
{
    public function __construct(
        public StockLocation $location,
        public string $quantity,
    ) {
    }
}
