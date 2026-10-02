<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use BcMath\Number;

/**
 * What one unit of a product is worth, the weighted average of what came in (docs/SPEC.md § 7): the value of the
 * stock already valued over its quantity. With none valued yet, or a stock of nothing, the product's own cost price.
 */
final class WeightedAverageCost
{
    /**
     * @param numeric-string $valuedQuantity the quantity of the movements that carry a cost, signed
     * @param numeric-string $valuedAmount   what that quantity is worth, signed
     *
     * @return numeric-string|null four decimals
     */
    public static function of(string $valuedQuantity, string $valuedAmount, ?string $costPrice): ?string
    {
        $quantity = new Number($valuedQuantity);
        if (1 === $quantity->compare(0)) {
            return new Number($valuedAmount)->div($quantity, 10)->round(4)->value;
        }
        if (null === $costPrice || !is_numeric($costPrice)) {
            return null;
        }

        return new Number($costPrice)->round(4)->value;
    }
}
