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
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Invoices\Domain\CustomerCreditEntry;
use App\Module\Invoices\Domain\CustomerCreditRepository;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Module\Invoices\Domain\Payment;
use App\Module\Invoices\Domain\PaymentDetails;
use App\Shared\Application\Transactions;
use App\Shared\Domain\PaymentMethod;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A customer's credit balance (docs/SPEC.md § 7): money received that no invoice took is kept for them, and applied to
 * an invoice later as an ordinary payment. Both run in a transaction that holds the customer's row, so two applications
 * at once never both fit the balance before either, and both are audited on the customer.
 */
final readonly class ManageCustomerCredit
{
    public const string ENTITY_TYPE = 'customer';
    public const string DEPOSITED = 'customer.credit_deposited';
    public const string APPLIED = 'customer.credit_applied';
    /** What a payment made of credit says as its reference. */
    public const string PAYMENT_REFERENCE = 'credit_balance';

    public function __construct(
        private CustomerCreditRepository $credits,
        private CustomerRepository $customers,
        private InvoiceRepository $invoices,
        private Transactions $transactions,
        private CurrencyScales $scales,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** What the customer has to their credit, three decimals. */
    public function balance(Company $company, Uuid $customerId): string
    {
        $this->customer($company, $customerId);

        return $this->credits->balance($company->getId(), $customerId);
    }

    /**
     * @return list<CustomerCreditEntry>
     *
     * @throws CustomerNotFound
     */
    public function entries(Company $company, Uuid $customerId): array
    {
        $this->customer($company, $customerId);

        return $this->credits->entries($company->getId(), $customerId);
    }

    /**
     * Money the customer paid that no invoice took. The day is dated from nothing up to the company's today.
     *
     * @throws CustomerNotFound
     * @throws InvalidInvoice   on `amount` or `date`
     */
    public function deposit(Company $company, Uuid $customerId, PaymentDetails $details, ?Uuid $actorUserId): CustomerCreditEntry
    {
        return $this->transactions->run(function () use ($company, $customerId, $details, $actorUserId): CustomerCreditEntry {
            $customer = $this->customer($company, $customerId);
            $now = $this->clock->now();
            $today = $now->setTimezone(new \DateTimeZone($company->getTimezone()));
            $scale = $this->scales->of($company->getCurrency());
            $amount = Decimal::of($details->amount);
            if (0 !== Decimal::round($amount, $scale)->compare($amount)) {
                throw new InvalidInvoice('amount', \sprintf('The currency %s has %d decimals.', $company->getCurrency(), $scale));
            }
            if ($details->date->format('Y-m-d') > $today->format('Y-m-d')) {
                throw new InvalidInvoice('date', \sprintf('Money is received today at the latest, %s.', $today->format('Y-m-d')));
            }

            $entry = CustomerCreditEntry::deposit($customer, $details, $actorUserId, $now);
            $this->credits->save($entry);
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $customer->getId(), self::DEPOSITED, $actorUserId, ['amount' => $entry->getAmount(), 'date' => $details->date->format('Y-m-d')], $company->getId()));

            return $entry;
        });
    }

    /**
     * Pays `$amount` of an invoice from what the customer has to their credit: an ordinary payment on the invoice, dated
     * today, and the same amount off the balance.
     *
     * @throws InvoiceNotFound
     * @throws InvoiceTransitionRefused when the invoice is not issued
     * @throws InvalidInvoice           on `amount`: more than is due, more than is to the customer's credit, or not an amount
     */
    public function apply(Company $company, Uuid $invoiceId, ?string $amount, ?Uuid $actorUserId): Payment
    {
        return $this->transactions->run(function () use ($company, $invoiceId, $amount, $actorUserId): Payment {
            $invoice = $this->invoices->lockedOfIdInCompany($invoiceId, $company->getId()) ?? throw new InvoiceNotFound();
            $customer = $invoice->getCustomer();
            $now = $this->clock->now();
            $today = $now->setTimezone(new \DateTimeZone($company->getTimezone()));
            $balance = Decimal::of($this->credits->lockedBalance($company->getId(), $customer->getId()));
            if (null === $amount) {
                // As much as fits: what is due, or the whole balance when that is less.
                $due = Decimal::of($invoice->getIssuedFigures()->amountDue ?? '0');
                $amount = Decimal::format($due->compare($balance) < 0 ? $due : $balance, $this->scales->of($company->getCurrency()));
                if (Decimal::of($amount)->compare(0) <= 0) {
                    throw new InvalidInvoice('amount', 'There is nothing to apply: no credit, or nothing due.');
                }
            }
            $details = new PaymentDetails($today, $amount, PaymentMethod::Other, self::PAYMENT_REFERENCE);
            if (Decimal::of($details->amount)->compare($balance) > 0) {
                throw new InvalidInvoice('amount', \sprintf('Only %s is to the customer\'s credit.', Decimal::format($balance, 3)));
            }

            $payment = $invoice->recordPayment($details, $today, $this->scales->of($company->getCurrency()), $actorUserId, $now);
            $this->invoices->save($invoice);
            $this->credits->save(CustomerCreditEntry::applied($customer, $payment, $actorUserId, $now));
            $this->audit->record(new AuditEntry(ManageInvoices::ENTITY_TYPE, $invoice->getId(), ManagePayments::RECORDED, $actorUserId, [
                'paymentId' => $payment->getId()->toRfc4122(),
                'date' => $payment->getDate()->format('Y-m-d'),
                'amount' => $payment->getAmount(),
                'method' => $payment->getMethod()->value,
            ], $company->getId()));
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $customer->getId(), self::APPLIED, $actorUserId, ['amount' => $payment->getAmount(), 'invoiceId' => $invoice->getId()->toRfc4122()], $company->getId()));

            return $payment;
        });
    }

    /** @throws CustomerNotFound */
    private function customer(Company $company, Uuid $customerId): Customer
    {
        return $this->customers->ofIdInCompany($customerId, $company->getId()) ?? throw new CustomerNotFound();
    }
}
