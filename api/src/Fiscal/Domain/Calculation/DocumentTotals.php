<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/** Every figure a document prints, as decimal strings at the currency's scale; each column adds up to its total. */
final readonly class DocumentTotals
{
    /**
     * @param list<LineTotals>  $lines
     * @param string|null       $subtotalGross the typed gross before the document discount, for tax-inclusive entry
     * @param list<TaxTotal>    $taxes         the percentage taxes, in the order they first appear
     * @param list<ChargeTotal> $fixedCharges
     * @param list<TaxTotal>    $withholdings  only those whose threshold is reached
     */
    public function __construct(
        public array $lines,
        public string $subtotalNet,
        public ?string $subtotalGross,
        public string $documentDiscount,
        public string $netAfterDocumentDiscount,
        public array $taxes,
        public string $totalTax,
        public array $fixedCharges,
        public string $total,
        public array $withholdings,
        public string $amountDue,
    ) {
    }
}
