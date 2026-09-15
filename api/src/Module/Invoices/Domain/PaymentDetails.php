<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Shared\Domain\PaymentMethod;

/**
 * What a payment says: its day, an amount above zero with at most three decimals, how it was paid, a reference and
 * notes. Whether the amount fits the currency and what is due, and whether the day fits the invoice, is the invoice's
 * to say. Empty texts are absent.
 */
final readonly class PaymentDetails
{
    public const int REFERENCE_MAX = 64;
    public const int NOTES_MAX = InvoiceHeader::TEXT_MAX;
    private const string AMOUNT = '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/';

    public \DateTimeImmutable $date;
    /** Three decimals. */
    public string $amount;
    public ?string $reference;
    public ?string $notes;

    /** @throws InvalidInvoice */
    public function __construct(\DateTimeImmutable $date, string $amount, public PaymentMethod $method, ?string $reference = null, ?string $notes = null)
    {
        $this->date = new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
        $this->amount = self::amount($amount);
        $this->reference = self::text('reference', $reference, self::REFERENCE_MAX);
        $this->notes = self::text('notes', $notes, self::NOTES_MAX);
    }

    private static function amount(string $amount): string
    {
        $amount = trim($amount);
        if (1 !== preg_match(self::AMOUNT, $amount)) {
            throw new InvalidInvoice('amount', 'A payment is an amount above 0 with at most three decimals.');
        }
        [$units, $decimals] = [...explode('.', $amount), ''];
        $amount = $units.'.'.str_pad($decimals, 3, '0');
        if ('0.000' === $amount) {
            throw new InvalidInvoice('amount', 'A payment is an amount above 0 with at most three decimals.');
        }

        return $amount;
    }

    private static function text(string $field, ?string $value, int $max): ?string
    {
        $value = trim($value ?? '');
        if (mb_strlen($value) > $max) {
            throw new InvalidInvoice($field, \sprintf('At most %d characters.', $max));
        }

        return '' === $value ? null : $value;
    }
}
