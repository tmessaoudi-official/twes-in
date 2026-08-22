<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\State;

use Twes\Domain\Product\Product;
use Twes\Domain\Shared\RoundingMode;
use Twes\UI\Http\ApiResource\ProductPricingResource;
use Twes\UI\Http\ApiResource\ProductResource;

/**
 * The one translation from the {@see Product} aggregate to the resource the API returns.
 *
 * ONE PLACE, because {@see CreateProductProcessor} and {@see ProductProvider} both answer with the same
 * resource, and the day they disagree is the day `POST /api/products` and a later `GET` of the same product
 * describe it differently with nothing failing.
 *
 * ## The rounding modes here are the whole subtlety
 *
 * **The AUTHORED figure is read with `Unnecessary`; the DERIVED one is read with `HalfUp`.** Asking for the
 * field the product was authored with is a lookup and cannot round, so `Unnecessary` is both correct and a
 * tripwire — if it ever throws, something upstream asked for a value the aggregate did not author. Asking for
 * the OTHER field is a genuine derivation (`net = cost × (1 + rate)`, or `rate = (net − cost) ÷ cost`) and can
 * legitimately be inexact: a third of a dinar does not terminate.
 *
 * **`HalfUp` for display is safe precisely because it is NOT stored.** F4's warning is that a typed price must
 * never be rebuilt from a rounded rate; that hazard belongs to the WRITE path, and this class only reads.
 * `DoctrineProductRepository` persists the authored value with `Unnecessary` for exactly that reason.
 */
final class ProductRepresentation
{
    private function __construct() {}

    public static function of(Product $product): ProductResource
    {
        $pricing = $product->pricing();

        // BOTH FIGURES ARE RETURNED, and `authoredBy` is what tells a client which is authoritative. F4's
        // § "Bidirectional editing" requires a form showing three linked fields where none can lie, so a client
        // needs all three; what it must never do is write the derived one back without the user typing it.
        $rate = $pricing->profitRate(RoundingMode::HalfUp);

        return new ProductResource(
            $product->id(),
            $product->name(),
            $product->sku(),
            new ProductPricingResource(
                $product->cost()->currency()->code(),
                $product->cost()->amount(),
                $pricing->authoredBy()->value,
                // NULL WHEN THE COST IS ZERO, and that is F4 rather than a missing value: the rate would be a
                // division by zero, and the ruling is that the field shows empty — never `0`, which would claim
                // the product is sold at cost, and never an error, which would make a legitimate product
                // unreadable.
                $rate?->percentage(),
                $pricing->netPrice(RoundingMode::HalfUp)->amount(),
            ),
            $product->vatRate()->percentage(),
        );
    }
}
