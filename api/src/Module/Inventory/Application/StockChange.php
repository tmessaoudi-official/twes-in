<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\StockMovement;

/** What one row of a file did to the stock of one place: before and after, and the movement that did it. */
final readonly class StockChange
{
    /**
     * @param numeric-string $before
     * @param numeric-string $after
     */
    public function __construct(
        public StockMovement $movement,
        public string $place,
        public string $before,
        public string $after,
    ) {
    }
}
