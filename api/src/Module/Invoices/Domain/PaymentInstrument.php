<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A cheque or a traite received against an issued invoice (docs/SPEC.md § 7, 2026-09-21 18:40). It is not money: the
 * invoice stays due while the instrument is held or deposited, and only a cashed one becomes the payment it settled.
 * One that came back unpaid leaves a trace and no payment.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_instrument')]
#[ORM\Index(name: 'idx_payment_instrument_invoice', columns: ['invoice_id'])]
#[ORM\Index(name: 'idx_payment_instrument_company_status_due', columns: ['company_id', 'status', 'due_on'])]
class PaymentInstrument implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 8, enumType: InstrumentKind::class)]
    private InstrumentKind $kind;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $amount;

    #[ORM\Column(name: 'due_on', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dueOn;

    #[ORM\Column(length: InstrumentDetails::BANK_MAX, nullable: true)]
    private ?string $bank;

    #[ORM\Column(length: InstrumentDetails::NUMBER_MAX, nullable: true)]
    private ?string $number;

    #[ORM\Column(length: 12, enumType: InstrumentStatus::class)]
    private InstrumentStatus $status = InstrumentStatus::Held;

    /** The day it was cashed or came back unpaid; null while it is open. */
    #[ORM\Column(name: 'settled_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $settledOn = null;

    /** The payment cashing it recorded; null until it is cashed. */
    #[ORM\OneToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(name: 'payment_id', nullable: true, onDelete: 'SET NULL')]
    private ?Payment $payment = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $recordedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @internal an instrument is received through ManageInstruments */
    public function __construct(Invoice $invoice, InstrumentDetails $details, ?Uuid $recordedBy, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->invoice = $invoice;
        $this->company = $invoice->getCompany();
        $this->kind = $details->kind;
        $this->amount = $details->amount;
        $this->dueOn = $details->dueOn;
        $this->bank = $details->bank;
        $this->number = $details->number;
        $this->recordedBy = $recordedBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** Handed to the bank for collection. @throws InvoiceTransitionRefused unless it is still held */
    public function deposit(\DateTimeImmutable $now): void
    {
        if (InstrumentStatus::Held !== $this->status) {
            throw new InvoiceTransitionRefused(\sprintf('The %s is %s: only one held in the portfolio is deposited.', $this->kind->value, $this->status->value));
        }
        $this->status = InstrumentStatus::Deposited;
        $this->updatedAt = $now;
    }

    /**
     * Cashed: the payment it became, none when other money had already covered its invoice and all of it went on the
     * customer's account. @throws InvoiceTransitionRefused unless it is held or deposited.
     */
    public function cash(?Payment $payment, \DateTimeImmutable $day, \DateTimeImmutable $now): void
    {
        $this->settle(InstrumentStatus::Cashed, $day, $now);
        $this->payment = $payment;
    }

    /** Came back unpaid: no payment, and the invoice stays due. @throws InvoiceTransitionRefused unless it is held or deposited */
    public function refuse(\DateTimeImmutable $day, \DateTimeImmutable $now): void
    {
        $this->settle(InstrumentStatus::Unpaid, $day, $now);
    }

    /** Whether it may be taken out of the portfolio: only a held one, a mistake nothing has acted on. */
    public function isErasable(): bool
    {
        return InstrumentStatus::Held === $this->status;
    }

    private function settle(InstrumentStatus $to, \DateTimeImmutable $day, \DateTimeImmutable $now): void
    {
        if (!$this->status->isOpen()) {
            throw new InvoiceTransitionRefused(\sprintf('The %s is already %s.', $this->kind->value, $this->status->value));
        }
        $this->status = $to;
        $this->settledOn = new \DateTimeImmutable($day->format('Y-m-d'), new \DateTimeZone('UTC'));
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getKind(): InstrumentKind
    {
        return $this->kind;
    }

    /** Three decimals. */
    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getDueOn(): \DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function getBank(): ?string
    {
        return $this->bank;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function getStatus(): InstrumentStatus
    {
        return $this->status;
    }

    public function getSettledOn(): ?\DateTimeImmutable
    {
        return $this->settledOn;
    }

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function getRecordedBy(): ?Uuid
    {
        return $this->recordedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
