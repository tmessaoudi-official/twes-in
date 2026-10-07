<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Infrastructure\Quotes;

use App\Module\PriceLists\Infrastructure\StartingPrices;
use App\Module\Products\Domain\Product;
use App\Module\Quotes\Application\QuoteLinePrices;
use Symfony\Component\Uid\Uuid;

/** Answers the quotes module's `QuoteLinePrices` port out of this module. */
final readonly class PriceListQuoteLinePrices implements QuoteLinePrices
{
    public function __construct(private StartingPrices $prices)
    {
    }

    public function startingPrice(Product $product, Uuid $customerId, string $quantity): string
    {
        return $this->prices->of($product, $customerId, $quantity);
    }
}
