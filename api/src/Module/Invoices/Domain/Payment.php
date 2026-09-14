<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Money a customer paid against an issued invoice (docs/SPEC.md § 4 payment), recorded and deleted by its invoice. */
#[ORM\Entity]
#[ORM\Table(name: 'payment')]
#[ORM\Index(name: 'idx_payment_invoice', columns: ['invoice_id'])]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column(name: 'payment_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $amount;

    #[ORM\Column(length: 16, enumType: PaymentMethod::class)]
    private PaymentMethod $method;

    #[ORM\Column(length: PaymentDetails::REFERENCE_MAX, nullable: true)]
    private ?string $reference;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $recordedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @internal a payment is recorded by Invoice */
    public function __construct(Invoice $invoice, PaymentDetails $details, ?Uuid $recordedBy, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->invoice = $invoice;
        $this->date = $details->date;
        $this->amount = $details->amount;
        $this->method = $details->method;
        $this->reference = $details->reference;
        $this->notes = $details->notes;
        $this->recordedBy = $recordedBy;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    /** Three decimals. */
    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getMethod(): PaymentMethod
    {
        return $this->method;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
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
