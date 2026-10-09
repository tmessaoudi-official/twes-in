<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/** What one row of a product file did to the stock of one place, in the unit's own decimals. */
final readonly class ProductStockChange
{
    public function __construct(
        public string $place,
        public string $before,
        public string $after,
    ) {
    }
}
