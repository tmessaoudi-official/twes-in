<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Fiscal\Domain\Unit;
use App\Module\Products\Domain\Product;
use App\Tenancy\Domain\Company;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** One line of an invoice or credit note (docs/SPEC.md § 4 invoice_line), written by its document and never on its own. */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_line')]
#[ORM\Index(name: 'idx_invoice_line_invoice', columns: ['invoice_id'])]
#[ORM\Index(name: 'idx_invoice_line_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_invoice_line_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_invoice_line_unit', columns: ['unit_id'])]
#[ORM\Index(name: 'idx_invoice_line_source_delivery_note_line', columns: ['source_delivery_note_line_id'])]
class InvoiceLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

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

    /** The line after its own discount, as issuing fixed it; null on a draft, whose figures are worked out on every read. */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $lineNet = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $lineTax = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $lineGross = null;

    /** The delivery note line it invoices; an id rather than an association, delivery notes being their own module. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $sourceDeliveryNoteLineId;

    /** @var Collection<int, InvoiceLineTax> */
    #[ORM\OneToMany(targetEntity: InvoiceLineTax::class, mappedBy: 'line', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $taxes;

    /** @internal a line is written by Invoice */
    public function __construct(Invoice $invoice, int $position, InvoiceLineDetails $details)
    {
        $this->id = Uuid::v7();
        $this->invoice = $invoice;
        $this->company = $invoice->getCompany();
        $this->position = $position;
        $this->product = $details->product;
        $this->description = $details->description;
        $this->quantity = $details->quantity;
        $this->unit = $details->unit;
        $this->unitPriceNet = $details->unitPriceNet;
        $this->discountRate = $details->discountRate;
        $this->sourceDeliveryNoteLineId = $details->sourceDeliveryNoteLineId;
        $this->taxes = new ArrayCollection();
        foreach ($details->taxes as $index => $tax) {
            $this->taxes->add(new InvoiceLineTax($this, $index + 1, $tax));
        }
    }

    /**
     * @internal issuing writes what the line comes to, never recomputed afterwards
     *
     * @param array{net: string, tax: string, gross: string} $figures
     */
    public function fix(array $figures): void
    {
        $this->lineNet = $figures['net'];
        $this->lineTax = $figures['tax'];
        $this->lineGross = $figures['gross'];
    }

    /** @return array{net: string, tax: string, gross: string}|null what issuing fixed; null on a draft */
    public function getFixedFigures(): ?array
    {
        if (null === $this->lineNet || null === $this->lineTax || null === $this->lineGross) {
            return null;
        }

        return ['net' => $this->lineNet, 'tax' => $this->lineTax, 'gross' => $this->lineGross];
    }

    /** @return array{string|null, string, string, string, string, string|null, list<string>, string|null} compared the way InvoiceLineDetails::values() is */
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
            $this->sourceDeliveryNoteLineId?->toRfc4122(),
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

    /** The delivery note line it invoices; null for a line written by hand. */
    public function getSourceDeliveryNoteLineId(): ?Uuid
    {
        return $this->sourceDeliveryNoteLineId;
    }

    /** @return list<InvoiceLineTax> in the order they are printed */
    public function getTaxes(): array
    {
        return array_values($this->taxes->toArray());
    }

    public function getCompany(): Company
    {
        return $this->company;
    }
}
