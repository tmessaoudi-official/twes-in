<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Application;

use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use App\Module\Products\Domain\Product;

/**
 * What a customer pays for a product at a price, taxes included, counted as the documents count it. A port this module
 * owns, answered by the products', which keep that price, so neither calls into the other (docs/SPEC.md § 7, audit
 * 2026-10-06 C-4).
 */
interface TaxedPrices
{
    /**
     * @param string|null $unitPriceNet a price other than the shelf's, such as a promotion's; null for the shelf's
     *
     * @throws InvalidDocument           when the price cannot be totalled
     * @throws UnsupportedTaxCombination when the product's taxes combine in a way the calculator does not count
     */
    public function of(Product $product, int $quantity, ?string $unitPriceNet = null): string;
}
