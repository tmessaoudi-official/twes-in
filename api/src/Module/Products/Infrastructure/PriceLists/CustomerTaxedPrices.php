<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\PriceLists;

use App\Module\PriceLists\Application\TaxedPrices;
use App\Module\Products\Application\CustomerPrice;
use App\Module\Products\Domain\Product;

/** Answers the price lists' `TaxedPrices` port with the price check this module shows everywhere. */
final readonly class CustomerTaxedPrices implements TaxedPrices
{
    public function __construct(private CustomerPrice $prices)
    {
    }

    public function of(Product $product, int $quantity, ?string $unitPriceNet = null): string
    {
        return $this->prices->of($product, $quantity, $unitPriceNet);
    }
}
