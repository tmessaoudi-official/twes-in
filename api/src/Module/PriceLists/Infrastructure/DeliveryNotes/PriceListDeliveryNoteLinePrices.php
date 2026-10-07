<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Infrastructure\DeliveryNotes;

use App\Module\DeliveryNotes\Application\DeliveryNoteLinePrices;
use App\Module\PriceLists\Infrastructure\StartingPrices;
use App\Module\Products\Domain\Product;
use Symfony\Component\Uid\Uuid;

/** Answers the delivery notes module's `DeliveryNoteLinePrices` port out of this module. */
final readonly class PriceListDeliveryNoteLinePrices implements DeliveryNoteLinePrices
{
    public function __construct(private StartingPrices $prices)
    {
    }

    public function startingPrice(Product $product, Uuid $customerId, string $quantity): string
    {
        return $this->prices->of($product, $customerId, $quantity);
    }
}
