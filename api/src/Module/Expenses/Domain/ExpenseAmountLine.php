<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

use App\Fiscal\Domain\TaxComponent;

/**
 * One block of a supplier's bill as it is printed: a net amount above zero with at most three decimals and the rates
 * on it, such as a VAT rate and FODEC. Whether the amount fits the currency and the rates the company is the
 * expense's to say.
 */
final readonly class ExpenseAmountLine
{
    private const string AMOUNT = '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/';

    /** Three decimals. */
    public string $amountNet;

    /**
     * @param list<TaxComponent> $taxes
     *
     * @throws InvalidExpense
     */
    public function __construct(string $amountNet, public array $taxes)
    {
        $amount = trim($amountNet);
        if (1 !== preg_match(self::AMOUNT, $amount)) {
            throw new InvalidExpense('amountNet', 'A net amount is above 0 with at most three decimals.', 'invalid_amount', ['decimals' => 3]);
        }
        [$units, $decimals] = [...explode('.', $amount), ''];
        $amount = $units.'.'.str_pad($decimals, 3, '0');
        if ('0.000' === $amount) {
            throw new InvalidExpense('amountNet', 'A net amount is above 0 with at most three decimals.', 'invalid_amount', ['decimals' => 3]);
        }
        $this->amountNet = $amount;
    }
}
