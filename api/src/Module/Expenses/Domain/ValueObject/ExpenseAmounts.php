<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain\ValueObject;

use App\Fiscal\Domain\Model\TaxComponent;
use App\Module\Expenses\Domain\Exception\InvalidExpense;

/**
 * What a supplier's bill comes to as it is printed: its lines, the fixed charges it carries (the stamp), and the tax
 * amounts it prints where they differ from what twes-in works out, by tax code. A supplier rounding each line where
 * twes-in rounds each rate prints a millime or so apart, and the bill is what the deduction and TEJ rest on.
 */
final readonly class ExpenseAmounts
{
    public const int LINES_MAX = 50;

    /**
     * @param list<ExpenseAmountLine> $lines
     * @param list<TaxComponent>      $charges fixed charges per document
     * @param array<string, string>   $printed the amount printed for a tax, by its code
     *
     * @throws InvalidExpense
     */
    public function __construct(public array $lines, public array $charges = [], public array $printed = [])
    {
        if ([] === $lines || \count($lines) > self::LINES_MAX) {
            throw new InvalidExpense('lines', \sprintf('A bill has 1 to %d lines.', self::LINES_MAX));
        }
    }

    /**
     * A bill of one line, at one rate or none: a receipt.
     *
     * @param int $currencyScale the company currency's decimals
     *
     * @throws InvalidExpense
     */
    public static function one(string $amountNet, int $currencyScale, ?TaxComponent $tax = null): self
    {
        try {
            $line = new ExpenseAmountLine($amountNet, null === $tax ? [] : [$tax], $currencyScale);
        } catch (InvalidExpense $refused) {
            throw $refused->within('lines[0]');
        }

        return new self([$line]);
    }
}
