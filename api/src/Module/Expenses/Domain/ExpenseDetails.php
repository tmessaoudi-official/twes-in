<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

/**
 * What an expense says on its own: its day, what was bought, a net amount above zero with at most three decimals, the
 * vendor's own reference for it and notes. Whether the amount fits the company's currency is the expense's to say.
 * Empty texts are absent.
 */
final readonly class ExpenseDetails
{
    public const int DESCRIPTION_MAX = 200;
    public const int REFERENCE_MAX = 64;
    public const int NOTES_MAX = 5000;
    private const string AMOUNT = '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/';

    public \DateTimeImmutable $date;
    public string $description;
    /** Three decimals. */
    public string $amountNet;
    public ?string $reference;
    public ?string $notes;

    /** @throws InvalidExpense */
    public function __construct(\DateTimeImmutable $date, string $description, string $amountNet, ?string $reference = null, ?string $notes = null)
    {
        $this->date = new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
        $description = trim($description);
        if ('' === $description || mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new InvalidExpense('description', \sprintf('An expense says what was bought in 1 to %d characters.', self::DESCRIPTION_MAX));
        }
        $this->description = $description;
        $this->amountNet = self::amount($amountNet);
        $this->reference = self::text('reference', $reference, self::REFERENCE_MAX);
        $this->notes = self::text('notes', $notes, self::NOTES_MAX);
    }

    private static function amount(string $amount): string
    {
        $amount = trim($amount);
        if (1 !== preg_match(self::AMOUNT, $amount)) {
            throw new InvalidExpense('amountNet', 'A net amount is above 0 with at most three decimals.');
        }
        [$units, $decimals] = [...explode('.', $amount), ''];
        $amount = $units.'.'.str_pad($decimals, 3, '0');
        if ('0.000' === $amount) {
            throw new InvalidExpense('amountNet', 'A net amount is above 0 with at most three decimals.');
        }

        return $amount;
    }

    private static function text(string $field, ?string $value, int $max): ?string
    {
        $value = trim($value ?? '');
        if (mb_strlen($value) > $max) {
            throw new InvalidExpense($field, \sprintf('At most %d characters.', $max));
        }

        return '' === $value ? null : $value;
    }
}
