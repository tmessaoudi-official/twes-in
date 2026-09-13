<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

final readonly class LineTotals
{
    /**
     * @param string        $amount           quantity × unit price, rounded
     * @param string        $discount         the line discount's rounded allowance
     * @param string        $net              tax-exclusive: amount − discount; tax-inclusive: the extracted net, after the document discount
     * @param string|null   $gross            tax-inclusive only: amount − discount
     * @param string        $documentDiscount this line's share of the document discount
     * @param list<LineTax> $taxes
     */
    public function __construct(
        public string $amount,
        public string $discount,
        public string $net,
        public ?string $gross,
        public string $documentDiscount,
        public array $taxes,
    ) {
    }
}
