<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application\Tej;

/**
 * One certificate of withholding: a payment to one supplier, identified by its matricule, on one day, and the
 * operations it settled. The reference is the payer's own, which a later rectifying declaration names to modify or
 * cancel it, so it never changes.
 */
final readonly class TejCertificate
{
    /** @param non-empty-list<TejOperation> $operations */
    public function __construct(
        public string $beneficiary,
        public string $beneficiaryCategory,
        public string $beneficiaryName,
        public string $beneficiaryAddress,
        public string $beneficiaryEmail,
        public string $beneficiaryPhone,
        public \DateTimeImmutable $paidOn,
        public string $reference,
        public array $operations,
    ) {
    }

    /** @return array{amountNet: int, vatAmount: int, amountGross: int, withheld: int, netPaid: int} each operation's amount summed */
    public function totals(): array
    {
        $totals = ['amountNet' => 0, 'vatAmount' => 0, 'amountGross' => 0, 'withheld' => 0, 'netPaid' => 0];
        foreach ($this->operations as $operation) {
            $totals['amountNet'] += $operation->amountNet;
            $totals['vatAmount'] += $operation->vatAmount;
            $totals['amountGross'] += $operation->amountGross;
            $totals['withheld'] += $operation->withheld;
            $totals['netPaid'] += $operation->netPaid;
        }

        return $totals;
    }
}
