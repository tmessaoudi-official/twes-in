<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\Output;

use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use App\Module\Products\Application\CustomerPrice;
use App\Module\Products\Domain\Model\Product;

/**
 * What a product's unit sells for taxes included, as its resource shows it: the invoices' calculator through
 * CustomerPrice, whose taxes are read through the identity map, so a page of products reads each tax once.
 */
final readonly class ProductGrossPrice
{
    public function __construct(private CustomerPrice $prices)
    {
    }

    /** Null when the product's taxes cannot be counted together, which its form already says. */
    public function of(Product $product): ?string
    {
        try {
            return $this->prices->of($product, 1);
        } catch (InvalidDocument|UnsupportedTaxCombination) {
            return null;
        }
    }
}
