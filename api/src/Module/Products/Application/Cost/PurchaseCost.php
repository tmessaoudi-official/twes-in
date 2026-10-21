<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application\Cost;

use App\Fiscal\Domain\Calculation\Decimal;
use BcMath\Number;

/**
 * What a purchase cost the shop, from what the vendor invoiced taxes included: the VAT is left out when the company
 * gets it back, which its regime says, and a levy that is no VAT, such as FODEC, stays in it. A stamp is a document's,
 * never a unit's, and stays out. One rule for every reader: the product file's cost taxes included now, a vendor's
 * invoice later, which is where « FODEC dans le coût » and « Timbre dans le coût » become this rule's parameters
 * (docs/fiscal/TN.md § 3: FODEC falls at production and import, so only a purchase names it).
 */
final readonly class PurchaseCost
{
    /** A cost is kept with four decimals, as a product's is. */
    public const int SCALE = 4;

    /**
     * @param Number $gross        what was invoiced for one unit, taxes included
     * @param bool   $vatRecovered whether the company's regime gets its purchase VAT back
     * @param Number $vatRate      the percent of VAT in the figure; nothing is left out at none
     */
    public static function fromGross(Number $gross, bool $vatRecovered, Number $vatRate): string
    {
        $cost = $gross;
        if ($vatRecovered && 0 !== $vatRate->compare(0)) {
            $cost = $cost->div(1 + $vatRate / 100, Decimal::WORKING_SCALE);
        }

        return Decimal::round($cost, self::SCALE)->value;
    }
}
