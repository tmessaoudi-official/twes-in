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
     * @param int                   $scale            the currency's decimals: 3 for TND, 2 for EUR
     * @param bool                  $credit           a credit note: every computed figure is negative
     * @param list<LineInput>       $lines
     * @param string|null           $documentDiscount an amount, allocated across the lines by their base
     * @param list<TaxInput>        $documentTaxes    fixed charges and withholdings
     * @param array<string, string> $taxesLeft        by a percentage tax's code, what it comes to on a correction completing
     *                                                its document, typed positive: what the document charged less its earlier
     *                                                corrections, so they add up to it rather than each rounding alone
     */
    public function __construct(
        public int $scale,
        public bool $credit,
        public TaxBasis $basis,
        public RoundingPoint $roundingPoint,
        public array $lines,
        public ?string $documentDiscount = null,
        public array $documentTaxes = [],
        public array $taxesLeft = [],
    ) {
    }
}
