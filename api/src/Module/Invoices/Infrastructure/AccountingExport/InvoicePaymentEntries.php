<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\AccountingExport;

use App\Module\AccountingExport\Application\JournalPeriod;
use App\Module\AccountingExport\Application\PaymentEntries;
use App\Module\AccountingExport\Application\PaymentEntry;
use App\Module\Invoices\Domain\Payment;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;

/** The payments journal's entries: every payment the company recorded on an invoice, on the day it was received. */
final readonly class InvoicePaymentEntries implements PaymentEntries
{
    private const int BATCH = 200;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function receivedIn(Company $company, JournalPeriod $period): iterable
    {
        for ($offset = 0;; $offset += self::BATCH) {
            $found = $this->entityManager->createQueryBuilder()
                ->select('p', 'i')->from(Payment::class, 'p')->join('p.invoice', 'i')
                ->where('p.company = :company')->andWhere('p.date BETWEEN :from AND :to')
                ->setParameter('company', $company->getId(), 'uuid')
                ->setParameter('from', $period->from->format('Y-m-d'))->setParameter('to', $period->to->format('Y-m-d'))
                ->orderBy('p.date')->addOrderBy('p.createdAt')->addOrderBy('p.id')
                ->setFirstResult($offset)->setMaxResults(self::BATCH)
                ->getQuery()->getResult();
            $payments = array_values(array_filter(\is_array($found) ? $found : [], static fn (mixed $row): bool => $row instanceof Payment));
            foreach ($payments as $payment) {
                $invoice = $payment->getInvoice();
                $snapshot = $invoice->getCustomerSnapshot();
                yield new PaymentEntry(
                    $payment->getDate(),
                    $invoice->getNumber() ?? '',
                    $snapshot->number ?? $invoice->getCustomer()->getNumber(),
                    $snapshot->name ?? $invoice->getCustomer()->getProfile()->name,
                    $payment->getMethod()->value,
                    $payment->getReference() ?? '',
                    $payment->getAmount(),
                );
            }
            if (\count($payments) < self::BATCH) {
                return;
            }
        }
    }
}
