<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application\Tej;

use App\Module\Expenses\Domain\TejOperationCode;

/**
 * One operation of a TEJ certificate: every amount in millimes, whole, as the cahier des charges asks ("en millimes
 * sans partie décimale"); the rates as percentages with at most two decimals and no trailing zero.
 */
final readonly class TejOperation
{
    public function __construct(
        public TejOperationCode $code,
        /** The year of the supplier's invoice. */
        public int $invoiceYear,
        public int $amountNet,
        public string $withholdingRate,
        public string $vatRate,
        public int $vatAmount,
        public int $amountGross,
        public int $withheld,
        /** What the supplier was handed: the gross less what was withheld. */
        public int $netPaid,
    ) {
    }
}
