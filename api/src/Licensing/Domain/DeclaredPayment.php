<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

/** What a company says it paid: how much, how, when, and what names the payment on their side. */
final readonly class DeclaredPayment
{
    public const int NOTE_MAX = 500;
    public const int REFERENCE_MAX = 64;
    private const string AMOUNT = '/^(0|[1-9]\d{0,9})(\.\d{1,3})?$/';
    private const string CURRENCY = '/^[A-Z]{3}$/';

    public function __construct(
        /** A decimal string, as every amount in this API. */
        public string $amount,
        public string $currency,
        public PaymentMethod $method,
        /** The day the company says it paid. */
        public \DateTimeImmutable $paidOn,
        /** A receipt number, a transfer reference, a cheque number. */
        public ?string $reference = null,
        public ?string $note = null,
    ) {
        if (1 !== preg_match(self::AMOUNT, $amount) || 0.0 === (float) $amount) {
            throw new InvalidPayment('amount: a decimal amount above zero, with at most three decimals.');
        }
        if (1 !== preg_match(self::CURRENCY, $currency)) {
            throw new InvalidPayment('currency: an ISO 4217 code.');
        }
        if (null !== $reference && mb_strlen($reference) > self::REFERENCE_MAX) {
            throw new InvalidPayment(\sprintf('reference: at most %d characters.', self::REFERENCE_MAX));
        }
        if (null !== $note && mb_strlen($note) > self::NOTE_MAX) {
            throw new InvalidPayment(\sprintf('note: at most %d characters.', self::NOTE_MAX));
        }
    }
}
