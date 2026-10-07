<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Domain;

/**
 * What a quote says besides its customer and its lines: the customer's own reference, notes printed and notes kept
 * inside the company, and a discount on the whole quote as an amount, which the invoice it becomes carries. Empty
 * texts are absent.
 */
final readonly class QuoteHeader
{
    public const int REFERENCE_MAX = 64;
    public const int TEXT_MAX = 5000;
    private const string AMOUNT = '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/';

    public ?string $customerReference;
    public ?string $notesPrinted;
    public ?string $notesInternal;
    /** Three decimals; whether it fits the currency is the use case's to say. */
    public ?string $discountAmount;

    /** @throws InvalidQuote */
    public function __construct(
        ?string $customerReference = null,
        ?string $notesPrinted = null,
        ?string $notesInternal = null,
        ?string $discountAmount = null,
    ) {
        $this->customerReference = self::text('customerReference', $customerReference, self::REFERENCE_MAX);
        $this->notesPrinted = self::text('notesPrinted', $notesPrinted, self::TEXT_MAX);
        $this->notesInternal = self::text('notesInternal', $notesInternal, self::TEXT_MAX);
        $this->discountAmount = self::amount($discountAmount);
    }

    /** @return list<string> the fields whose values differ from the other's, in the order a form shows them */
    public function differencesFrom(self $other): array
    {
        $changed = [];
        foreach (['customerReference', 'notesPrinted', 'notesInternal', 'discountAmount'] as $field) {
            if ($this->{$field} !== $other->{$field}) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private static function text(string $field, ?string $value, int $max): ?string
    {
        $value = trim($value ?? '');
        if (mb_strlen($value) > $max) {
            throw new InvalidQuote($field, \sprintf('At most %d characters.', $max));
        }

        return '' === $value ? null : $value;
    }

    private static function amount(?string $amount): ?string
    {
        $amount = trim($amount ?? '');
        if ('' === $amount) {
            return null;
        }
        if (1 !== preg_match(self::AMOUNT, $amount)) {
            throw new InvalidQuote('discountAmount', 'A discount is an amount from 0 with at most three decimals.');
        }
        [$units, $decimals] = [...explode('.', $amount), ''];

        return $units.'.'.str_pad($decimals, 3, '0');
    }
}
