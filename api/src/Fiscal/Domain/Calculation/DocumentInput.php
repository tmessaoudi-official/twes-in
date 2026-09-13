<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/** What the calculator totals: lines authored positive, and the document's own settings. */
final readonly class DocumentInput
{
    /**
     * @param int             $scale            the currency's decimals: 3 for TND, 2 for EUR
     * @param bool            $credit           a credit note: every computed figure is negative
     * @param list<LineInput> $lines
     * @param string|null     $documentDiscount an amount, allocated across the lines by their base
     * @param list<TaxInput>  $documentTaxes    fixed charges and withholdings
     */
    public function __construct(
        public int $scale,
        public bool $credit,
        public TaxBasis $basis,
        public RoundingPoint $roundingPoint,
        public array $lines,
        public ?string $documentDiscount = null,
        public array $documentTaxes = [],
    ) {
    }
}
