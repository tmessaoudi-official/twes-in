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
 * One block of a supplier's bill as it is printed: a net amount above zero with no more decimals than the company's
 * currency has (three at most, what is stored) and the rates on it, such as a VAT rate and FODEC. Whether the rates are
 * the company's is the expense's to say.
 */
final readonly class ExpenseAmountLine
{
    /** The decimals an amount is stored with. */
    private const int STORED = 3;

    /** Three decimals. */
    public string $amountNet;

    /**
     * @param list<TaxComponent> $taxes
     * @param int                $currencyScale the company currency's decimals
     *
     * @throws InvalidExpense
     */
    public function __construct(string $amountNet, public array $taxes, int $currencyScale)
    {
        $allowed = min($currencyScale, self::STORED);
        $refused = new InvalidExpense('amountNet', \sprintf('A net amount is above 0 with at most %d decimals.', $allowed), 'invalid_amount', ['decimals' => $allowed]);
        $amount = trim($amountNet);
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,10})'.(0 === $allowed ? '' : '(\.[0-9]{1,'.$allowed.'})?').'$/', $amount)) {
            throw $refused;
        }
        [$units, $decimals] = [...explode('.', $amount), ''];
        $amount = $units.'.'.str_pad($decimals, self::STORED, '0');
        if ('0.000' === $amount) {
            throw $refused;
        }
        $this->amountNet = $amount;
    }
}
