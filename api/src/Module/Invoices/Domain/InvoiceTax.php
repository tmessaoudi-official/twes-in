<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A tax charged on a whole invoice (docs/SPEC.md § 4 invoice_tax): a fixed charge such as a stamp duty, or a
 * withholding on the total from a threshold. It keeps the code, amount, rate and threshold its component had when the
 * invoice was written, taken again at issue.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_tax')]
#[ORM\Index(name: 'idx_invoice_tax_invoice', columns: ['invoice_id'])]
#[ORM\Index(name: 'idx_invoice_tax_component', columns: ['tax_component_id'])]
class InvoiceTax
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'documentTaxes')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(targetEntity: TaxComponent::class)]
    #[ORM\JoinColumn(name: 'tax_component_id', nullable: false)]
    private TaxComponent $taxComponent;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(length: 32, enumType: TaxKind::class)]
    private TaxKind $kind;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3, nullable: true)]
    private ?string $rate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $amount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $threshold = null;

    /** @internal a document's taxes are written by its invoice */
    public function __construct(Invoice $invoice, int $position, TaxComponent $taxComponent)
    {
        $this->id = Uuid::v7();
        $this->invoice = $invoice;
        $this->position = $position;
        $this->taxComponent = $taxComponent;
        $this->retake();
    }

    /** @internal the code, kind, rate, amount and threshold its component has now */
    public function retake(): void
    {
        $this->code = $this->taxComponent->getCode();
        $this->kind = $this->taxComponent->getKind();
        $this->rate = self::decimal($this->taxComponent->getRate());
        $this->amount = self::decimal($this->taxComponent->getAmount());
        $this->threshold = self::decimal($this->taxComponent->getThreshold());
    }

    private static function decimal(?string $value): ?string
    {
        return null === $value ? null : Decimal::format(Decimal::of($value), 3);
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getTaxComponent(): TaxComponent
    {
        return $this->taxComponent;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getKind(): TaxKind
    {
        return $this->kind;
    }

    /** A withholding's percentage with three decimals; null for a fixed charge. */
    public function getRate(): ?string
    {
        return $this->rate;
    }

    /** A fixed charge's amount with three decimals; null for a withholding. */
    public function getAmount(): ?string
    {
        return $this->amount;
    }

    /** The total from which a withholding applies, with three decimals; null for a fixed charge. */
    public function getThreshold(): ?string
    {
        return $this->threshold;
    }
}
