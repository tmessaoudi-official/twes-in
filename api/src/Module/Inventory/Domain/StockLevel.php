<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use Symfony\Component\Uid\Uuid;

/** How much of a product a location holds: the sum of its movements there, signed, with three decimals. */
final readonly class StockLevel
{
    public function __construct(public Uuid $productId, public Uuid $locationId, public string $quantity)
    {
    }
}
