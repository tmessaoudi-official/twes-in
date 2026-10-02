<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use Symfony\Component\Uid\Uuid;

/** One price a list is written with: a product, the quantity it applies from, and the net unit price. */
final readonly class PriceListItemInput
{
    public function __construct(public Uuid $productId, public string $minQuantity, public string $unitPriceNet)
    {
    }
}
