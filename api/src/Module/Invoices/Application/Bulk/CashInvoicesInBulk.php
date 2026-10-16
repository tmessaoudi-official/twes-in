<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Bulk;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Application\Payment\ManagePayments;
use App\Module\Invoices\Domain\Repository\InvoiceRepository;
use App\Module\Invoices\Domain\ValueObject\PaymentDetails;
use App\Shared\Application\Transactions;
use App\Shared\Domain\PaymentMethod;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * « Encaisser » on a list's selection: each invoice paid for all it still owes, by one method on one day, in one
 * transaction. What is sent is the preview the person confirmed, row by row; it is checked again with every invoice
 * held, and when it no longer matches, an invoice paid or credited since, an amount that moved, a row more or less,
 * nothing is recorded. Each payment is then recorded as one recorded alone would be, audited on its invoice.
 */
final readonly class CashInvoicesInBulk
{
    public function __construct(
        private InvoiceRepository $invoices,
        private CheckInvoicesInBulk $check,
        private ManagePayments $payments,
        private Transactions $transactions,
    ) {
    }

    /**
     * @param list<array{id: Uuid, amount: string}> $rows the preview's included rows, in its order
     *
     * @return string the total recorded, with three decimals
     *
     * @throws BulkChanged               when the rows are not what the check would include now
     * @throws \InvalidArgumentException on no row or too many, or a day after today
     */
    public function cash(Company $company, array $rows, PaymentMethod $method, \DateTimeImmutable $day, ?string $reference, ?Uuid $actorUserId): string
    {
        return $this->transactions->run(function () use ($company, $rows, $method, $day, $reference, $actorUserId): string {
            $company->assertOpenOn($day);
            $ids = array_column($rows, 'id');
            // Held in one order whoever cashes, so two people cashing overlapping selections wait rather than deadlock.
            $held = $ids;
            usort($held, static fn (Uuid $a, Uuid $b): int => strcmp($a->toRfc4122(), $b->toRfc4122()));
            foreach ($held as $id) {
                $this->invoices->lockedOfIdInCompany($id, $company->getId());
            }

            $now = [];
            foreach ($this->check->check($company, BulkAction::Pay, $ids, day: $day) as $row) {
                $now[] = $row->included() ? ['id' => $row->id->toRfc4122(), 'amount' => $row->amount] : null;
            }
            $sent = array_map(static fn (array $row): array => ['id' => $row['id']->toRfc4122(), 'amount' => $row['amount']], $rows);
            if ($now !== $sent) {
                throw new BulkChanged('The invoices changed since they were checked: check them again.');
            }

            $total = Decimal::zero();
            foreach ($rows as $row) {
                $this->payments->record($company, $row['id'], new PaymentDetails($day, $row['amount'], $method, $reference), $actorUserId);
                $total = $total->add(Decimal::of($row['amount']));
            }

            return Decimal::format($total, 3);
        });
    }
}
