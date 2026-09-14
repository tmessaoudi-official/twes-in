<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Fiscal\Domain\Unit;
use App\Module\Products\Domain\Product;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** One line of an invoice or credit note (docs/SPEC.md § 4 invoice_line), written by its document and never on its own. */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_line')]
#[ORM\Index(name: 'idx_invoice_line_invoice', columns: ['invoice_id'])]
#[ORM\Index(name: 'idx_invoice_line_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_invoice_line_unit', columns: ['unit_id'])]
class InvoiceLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: true)]
    private ?Product $product;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $quantity;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(name: 'unit_id', nullable: false)]
    private Unit $unit;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $unitPriceNet;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3, nullable: true)]
    private ?string $discountRate;

    /** @var Collection<int, InvoiceLineTax> */
    #[ORM\OneToMany(targetEntity: InvoiceLineTax::class, mappedBy: 'line', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $taxes;

    /** @internal a line is written by Invoice */
    public function __construct(Invoice $invoice, int $position, InvoiceLineDetails $details)
    {
        $this->id = Uuid::v7();
        $this->invoice = $invoice;
        $this->position = $position;
        $this->product = $details->product;
        $this->description = $details->description;
        $this->quantity = $details->quantity;
        $this->unit = $details->unit;
        $this->unitPriceNet = $details->unitPriceNet;
        $this->discountRate = $details->discountRate;
        $this->taxes = new ArrayCollection();
        foreach ($details->taxes as $index => $tax) {
            $this->taxes->add(new InvoiceLineTax($this, $index + 1, $tax));
        }
    }

    /** @internal issuing takes each tax's code, rate and VAT base behaviour again, as they stand on the issue day */
    public function retakeTaxes(): void
    {
        foreach ($this->taxes as $tax) {
            $tax->retake();
        }
    }

    /** @return array{string|null, string, string, string, string, string|null, list<string>} compared the way InvoiceLineDetails::values() is */
    public function values(): array
    {
        return [
            $this->product?->getId()->toRfc4122(),
            $this->description,
            $this->quantity,
            $this->unit->getId()->toRfc4122(),
            $this->unitPriceNet,
            $this->discountRate,
            array_map(static fn (InvoiceLineTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), $this->getTaxes()),
        ];
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function getUnitPriceNet(): string
    {
        return $this->unitPriceNet;
    }

    /** A percentage with three decimals; null for no discount. */
    public function getDiscountRate(): ?string
    {
        return $this->discountRate;
    }

    /** @return list<InvoiceLineTax> in the order they are printed */
    public function getTaxes(): array
    {
        return array_values($this->taxes->toArray());
    }
}
