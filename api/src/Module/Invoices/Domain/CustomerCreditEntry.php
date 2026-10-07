<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Module\Customers\Domain\Customer;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One movement of a customer's credit balance (docs/SPEC.md § 7): money received that no invoice took, added, or
 * credit paid into an invoice, taken. The balance is the sum of the amounts; entries are never edited, a mistake is
 * undone by deleting the payment that applied the credit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_credit_entry')]
#[ORM\Index(name: 'idx_customer_credit_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_customer_credit_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_customer_credit_payment', columns: ['payment_id'])]
class CustomerCreditEntry implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(name: 'entry_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 16, enumType: CreditEntryKind::class)]
    private CreditEntryKind $kind;

    /** Signed, three decimals: above zero adds to what the customer has to their credit. */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $amount;

    #[ORM\Column(length: PaymentDetails::REFERENCE_MAX, nullable: true)]
    private ?string $reference;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes;

    /** The invoice credit was applied to, the one a credit note gave money back from, or the one paid beyond. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $invoiceId;

    /** The payment that applied it, which gives the credit back when deleted; null for a trop-perçu. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $paymentId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $recordedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(Customer $customer, CreditEntryKind $kind, \DateTimeImmutable $date, string $amount, ?string $reference, ?string $notes, ?Uuid $invoiceId, ?Uuid $paymentId, ?Uuid $recordedBy, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $customer->getCompany();
        $this->customer = $customer;
        $this->kind = $kind;
        $this->date = $date;
        $this->amount = $amount;
        $this->reference = $reference;
        $this->notes = $notes;
        $this->invoiceId = $invoiceId;
        $this->paymentId = $paymentId;
        $this->recordedBy = $recordedBy;
        $this->createdAt = $now;
    }

    /** Money the customer paid beyond `$invoice`; `$details` says how much, when and how, already checked. */
    public static function overpayment(Invoice $invoice, PaymentDetails $details, ?Uuid $recordedBy, \DateTimeImmutable $now): self
    {
        return new self($invoice->getCustomer(), CreditEntryKind::Overpayment, $details->date, $details->amount, $details->reference, $details->notes, $invoice->getId(), null, $recordedBy, $now);
    }

    /** Credit paid into an invoice by this payment: `$payment`'s amount leaves the balance. */
    public static function applied(Customer $customer, Payment $payment, ?Uuid $recordedBy, \DateTimeImmutable $now): self
    {
        $invoice = $payment->getInvoice();

        return new self($customer, CreditEntryKind::Applied, $payment->getDate(), '-'.$payment->getAmount(), null, null, $invoice->getId(), $payment->getId(), $recordedBy, $now);
    }

    /** What a credit note gave back of money its invoice was already paid: `$amount` joins the balance on `$date`. */
    public static function credited(Customer $customer, Invoice $invoice, string $creditNoteNumber, string $amount, \DateTimeImmutable $date, ?Uuid $recordedBy, \DateTimeImmutable $now): self
    {
        return new self($customer, CreditEntryKind::Credited, $date, $amount, $creditNoteNumber, null, $invoice->getId(), null, $recordedBy, $now);
    }

    /** The same amount leaving the balance again, paid back to the customer rather than kept for them. */
    public static function refunded(Customer $customer, Invoice $invoice, string $creditNoteNumber, string $amount, \DateTimeImmutable $date, ?Uuid $recordedBy, \DateTimeImmutable $now): self
    {
        return new self($customer, CreditEntryKind::Refunded, $date, '-'.$amount, $creditNoteNumber, null, $invoice->getId(), null, $recordedBy, $now);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getKind(): CreditEntryKind
    {
        return $this->kind;
    }

    /** Signed, three decimals. */
    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getInvoiceId(): ?Uuid
    {
        return $this->invoiceId;
    }

    public function getPaymentId(): ?Uuid
    {
        return $this->paymentId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
