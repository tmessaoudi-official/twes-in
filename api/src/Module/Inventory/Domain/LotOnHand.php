<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

/** How much of one lot is at a location. */
final readonly class LotOnHand
{
    /** @param numeric-string $quantity */
    public function __construct(public StockLot $lot, public string $quantity)
    {
    }
}
