<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain\ValueObject;

use App\Fiscal\Domain\Calculation\Decimal;

/**
 * The part of a bill at one VAT rate, as TEJ declares a payment (docs/research/tax-data-tunisia.md § 2.2): its amount
 * excluding VAT, FODEC inside it, its VAT, and their sum, on which the withholding is taken. The stamp is in none.
 * Amounts with three decimals; the rate is null for what no VAT taxes.
 */
final readonly class WithholdingOperation
{
    public function __construct(
        public ?string $vatRate,
        public string $amountExcludingVat,
        public string $vat,
        public string $amountIncludingVat,
    ) {
    }

    /** The withholding at a rate on this part's amount taxes included, rounded at the currency's scale. */
    public function withheld(string $rate, int $currencyScale): string
    {
        return Decimal::format(Decimal::round(Decimal::of($this->amountIncludingVat)->mul(Decimal::of($rate))->div(100, Decimal::WORKING_SCALE), $currencyScale), 3);
    }
}
