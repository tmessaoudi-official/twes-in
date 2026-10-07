<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Fiscal\Domain\Calculation\Decimal;

/**
 * What a line of a final invoice gives back of a deposit invoice: the deposit, and the amount of each of the line's
 * taxes the deposit charged on the line it gives back, by code. Those amounts are subtracted as charged, never
 * recomputed, so a deposit and the invoice deducting it add up to the undivided operation (docs/fiscal FR.md § 2b).
 */
final readonly class Deduction
{
    /** @var array<string, string> */
    public array $taxes;

    /**
     * @param array<string, string> $taxes amounts at most three decimals, never negative
     *
     * @throws InvalidInvoice
     */
    public function __construct(public Invoice $deposit, array $taxes)
    {
        if (!$deposit->isDeposit()) {
            throw new InvalidInvoice('deductsInvoiceId', \sprintf('The %s %s is not a deposit invoice: only a deposit is given back on another invoice.', $deposit->getType()->value, $deposit->getNumber() ?? $deposit->getId()->toRfc4122()));
        }
        $kept = [];
        foreach ($taxes as $code => $amount) {
            if (1 !== preg_match('/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/', $amount)) {
                throw new \LogicException(\sprintf('What a deposit charged of %s is a positive amount, not "%s".', $code, $amount));
            }
            $kept[$code] = Decimal::format(Decimal::of($amount), 3);
        }
        $this->taxes = $kept;
    }

    /** @return array{string, array<string, string>} what two deductions are compared on */
    public function values(): array
    {
        return [$this->deposit->getId()->toRfc4122(), $this->taxes];
    }
}
