<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Application;

/** What a company's owners are told once the operator has decided on the payment they declared. */
final readonly class PaymentDecidedMail
{
    public function __construct(
        public string $to,
        public string $locale,
        public string $companyName,
        public bool $confirmed,
        public string $amount,
        public string $currency,
        /** The day the subscription is now paid through, where one was confirmed. */
        public ?string $paidThrough,
        public ?string $note,
        public string $loginUrl,
    ) {
    }
}
