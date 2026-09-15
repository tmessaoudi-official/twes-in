<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use App\Fiscal\Domain\Unit;
use App\Module\Products\Domain\Product;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** One line of a delivery note (docs/SPEC.md § 4 delivery_note_line), written by its note and never on its own. */
#[ORM\Entity]
#[ORM\Table(name: 'delivery_note_line')]
#[ORM\Index(name: 'idx_delivery_note_line_note', columns: ['delivery_note_id'])]
#[ORM\Index(name: 'idx_delivery_note_line_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_delivery_note_line_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_delivery_note_line_unit', columns: ['unit_id'])]
class DeliveryNoteLine implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: DeliveryNote::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'delivery_note_id', nullable: false, onDelete: 'CASCADE')]
    private DeliveryNote $deliveryNote;

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

    /** @var Collection<int, DeliveryNoteLineTax> */
    #[ORM\OneToMany(targetEntity: DeliveryNoteLineTax::class, mappedBy: 'line', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $taxes;

    /** @internal a line is written by DeliveryNote */
    public function __construct(DeliveryNote $deliveryNote, int $position, DeliveryNoteLineDetails $details)
    {
        $this->id = Uuid::v7();
        $this->deliveryNote = $deliveryNote;
        $this->company = $deliveryNote->getCompany();
        $this->position = $position;
        $this->product = $details->product;
        $this->description = $details->description;
        $this->quantity = $details->quantity;
        $this->unit = $details->unit;
        $this->unitPriceNet = $details->unitPriceNet;
        $this->taxes = new ArrayCollection();
        foreach ($details->taxes as $index => $tax) {
            $this->taxes->add(new DeliveryNoteLineTax($this, $index + 1, $tax));
        }
    }

    /** @internal validation takes each tax's code, rate and VAT base behaviour again, as they stand on the issue day */
    public function retakeTaxes(): void
    {
        foreach ($this->taxes as $tax) {
            $tax->retake();
        }
    }

    /** @return array{string|null, string, string, string, string, list<string>} compared the way DeliveryNoteLineDetails::values() is */
    public function values(): array
    {
        return [
            $this->product?->getId()->toRfc4122(),
            $this->description,
            $this->quantity,
            $this->unit->getId()->toRfc4122(),
            $this->unitPriceNet,
            array_map(static fn (DeliveryNoteLineTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), $this->getTaxes()),
        ];
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDeliveryNote(): DeliveryNote
    {
        return $this->deliveryNote;
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

    /** @return list<DeliveryNoteLineTax> in the order they are printed */
    public function getTaxes(): array
    {
        return array_values($this->taxes->toArray());
    }

    public function getCompany(): Company
    {
        return $this->company;
    }
}
