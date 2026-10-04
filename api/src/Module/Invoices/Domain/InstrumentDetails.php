<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Shared\Domain\PaymentMethod;

/**
 * What an instrument received says: its kind, an amount above zero with at most three decimals, the day it falls due,
 * the bank it is drawn on and its number. Whether the amount fits the currency and what is due, and whether the day
 * fits the invoice, is the use case's to say. Empty texts are absent.
 */
final readonly class InstrumentDetails
{
    public const int BANK_MAX = 80;
    public const int NUMBER_MAX = PaymentDetails::REFERENCE_MAX;

    public \DateTimeImmutable $dueOn;
    /** Three decimals. */
    public string $amount;
    public ?string $bank;
    public ?string $number;

    /** @throws InvalidInvoice */
    public function __construct(public InstrumentKind $kind, \DateTimeImmutable $dueOn, string $amount, ?string $bank = null, ?string $number = null)
    {
        $this->dueOn = new \DateTimeImmutable($dueOn->format('Y-m-d'), new \DateTimeZone('UTC'));
        // The amount and the number are read the way a payment's are, so a cheque cashed becomes the payment it says.
        $payment = new PaymentDetails($this->dueOn, $amount, PaymentMethod::Check, $number);
        $this->amount = $payment->amount;
        $this->number = $payment->reference;
        $bank = trim($bank ?? '');
        if (mb_strlen($bank) > self::BANK_MAX) {
            throw new InvalidInvoice('bank', \sprintf('At most %d characters.', self::BANK_MAX));
        }
        $this->bank = '' === $bank ? null : $bank;
    }
}
