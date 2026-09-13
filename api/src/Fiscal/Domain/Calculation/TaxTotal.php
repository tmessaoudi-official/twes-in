<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

final readonly class TaxTotal
{
    /** @param string $documentDiscount the document discount carried by the lines this tax applies to */
    public function __construct(
        public string $code,
        public Rate $rate,
        public string $base,
        public string $documentDiscount,
        public string $amount,
    ) {
    }
}
