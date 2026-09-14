<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\CurrencyScales;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Module\Invoices\Domain\Payment;
use App\Module\Invoices\Domain\PaymentDetails;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Payments recorded on an issued invoice and deleted from it (docs/SPEC.md § 7, 2026-09-14), each in a transaction that
 * holds the invoice's row: two payments recorded at once never both fit what was due before either. Both are audited
 * on the invoice.
 */
final readonly class ManagePayments
{
    public const string RECORDED = 'payment.recorded';
    public const string DELETED = 'payment.deleted';

    public function __construct(
        private InvoiceRepository $invoices,
        private Transactions $transactions,
        private CurrencyScales $scales,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvoiceNotFound
     * @throws InvoiceTransitionRefused when the invoice is not issued
     * @throws InvalidInvoice           when the amount or the day does not fit the invoice
     */
    public function record(Company $company, Uuid $invoiceId, PaymentDetails $details, ?Uuid $actorUserId): Payment
    {
        return $this->transactions->run(function () use ($company, $invoiceId, $details, $actorUserId): Payment {
            $invoice = $this->invoices->lockedOfIdInCompany($invoiceId, $company->getId()) ?? throw new InvoiceNotFound();
            $now = $this->clock->now();
            $today = $now->setTimezone(new \DateTimeZone($company->getTimezone()));
            $payment = $invoice->recordPayment($details, $today, $this->scales->of($company->getCurrency()), $actorUserId, $now);
            $this->invoices->save($invoice);
            $this->trail($company, $invoice, self::RECORDED, $payment, $actorUserId);

            return $payment;
        });
    }

    /**
     * @throws InvoiceNotFound
     * @throws PaymentNotFound
     */
    public function delete(Company $company, Uuid $invoiceId, Uuid $paymentId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $invoiceId, $paymentId, $actorUserId): void {
            $invoice = $this->invoices->lockedOfIdInCompany($invoiceId, $company->getId()) ?? throw new InvoiceNotFound();
            $payment = $invoice->payment($paymentId) ?? throw new PaymentNotFound();
            $invoice->removePayment($payment, $this->clock->now());
            $this->invoices->save($invoice);
            $this->trail($company, $invoice, self::DELETED, $payment, $actorUserId);
        });
    }

    private function trail(Company $company, Invoice $invoice, string $action, Payment $payment, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(ManageInvoices::ENTITY_TYPE, $invoice->getId(), $action, $actorUserId, [
            'paymentId' => $payment->getId()->toRfc4122(),
            'date' => $payment->getDate()->format('Y-m-d'),
            'amount' => $payment->getAmount(),
            'method' => $payment->getMethod()->value,
        ], $company->getId()));
    }
}
