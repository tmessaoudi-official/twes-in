<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use BcMath\Number;

/**
 * A receipt's real cost entered after some of its goods have left (docs/SPEC.md § 7, the B-F4 ruling): the difference
 * it makes is split between the stock still there and the cost of the sales made since. At a weighted average every
 * unit leaving takes its share of the whole stock, so the stock as it stood right after the receipt keeps, after each
 * movement out, the part that movement did not take; later arrivals add to the stock without taking any of it back.
 */
final class LateCost
{
    private const int SCALE = 12;

    /**
     * @param numeric-string       $difference      what entering the cost changed the receipt's value by
     * @param numeric-string       $stockAfter      the valued quantity right after the receipt
     * @param list<numeric-string> $quantitiesSince the signed quantities of the valued movements since, in order, moves left out
     *
     * @return numeric-string|null what went with the goods gone, seven decimals, to take off the stock; null when nothing did
     */
    public static function soldShare(string $difference, string $stockAfter, array $quantitiesSince): ?string
    {
        $stock = new Number($stockAfter);
        $kept = new Number('1');
        if (1 !== $stock->compare(0)) {
            $kept = new Number('0');
        }
        foreach ($quantitiesSince as $quantity) {
            $moved = new Number($quantity);
            if (-1 === $moved->compare(0) && 1 === $kept->compare(0)) {
                $kept = 1 === $stock->add($moved)->compare(0)
                    ? $kept->mul($stock->add($moved)->div($stock, self::SCALE))
                    : new Number('0');
            }
            $stock = $stock->add($moved);
        }
        $sold = new Number($difference)->mul(new Number('1')->sub($kept))->round(7);
        if (0 === $sold->compare(0)) {
            return null;
        }

        return new Number('0.0000000')->add($sold)->value;
    }
}
