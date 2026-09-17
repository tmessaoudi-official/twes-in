<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Application;

/** What an operator is told the moment a company says it paid. */
final readonly class PaymentDeclaredMail
{
    public function __construct(
        public string $to,
        public string $locale,
        public string $companyName,
        public string $amount,
        public string $currency,
        public string $method,
        public string $paidOn,
        public ?string $reference,
        /** Where the operator decides on it. */
        public string $platformUrl,
    ) {
    }
}
