<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

/**
 * What an expense says on its own: its day, what was bought, the vendor's own reference for it, notes, and who was
 * paid when no vendor record names them (a car park, a notary). What it comes to is its bill, `ExpenseAmounts`.
 * Empty texts are absent.
 */
final readonly class ExpenseDetails
{
    public const int DESCRIPTION_MAX = 200;
    public const int REFERENCE_MAX = 64;
    public const int NOTES_MAX = 5000;
    public const int PAYEE_MAX = 160;

    public \DateTimeImmutable $date;
    public string $description;
    public ?string $reference;
    public ?string $notes;
    public ?string $payee;

    /** @throws InvalidExpense */
    public function __construct(\DateTimeImmutable $date, string $description, ?string $reference = null, ?string $notes = null, ?string $payee = null)
    {
        $this->date = new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
        $description = trim($description);
        if ('' === $description || mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new InvalidExpense('description', \sprintf('An expense says what was bought in 1 to %d characters.', self::DESCRIPTION_MAX));
        }
        $this->description = $description;
        $this->reference = self::text('reference', $reference, self::REFERENCE_MAX);
        $this->notes = self::text('notes', $notes, self::NOTES_MAX);
        $this->payee = self::text('payee', $payee, self::PAYEE_MAX);
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
