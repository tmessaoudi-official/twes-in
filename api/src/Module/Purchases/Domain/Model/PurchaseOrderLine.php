<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\Model;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderLineDetails;
use App\Shared\Domain\Service\CompanyOwned;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One product ordered, as much of it as was asked, and how much of it has arrived so far. The product is held by its id
 * and its reference and name as they read when the line was written: the products are their own module.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_order_line')]
#[ORM\Index(name: 'idx_purchase_order_line_order', columns: ['purchase_order_id'])]
#[ORM\Index(name: 'idx_purchase_order_line_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_purchase_order_line_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_purchase_order_line_unit', columns: ['unit_id'])]
class PurchaseOrderLine implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'purchase_order_id', nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $order;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(type: 'uuid')]
    private Uuid $productId;

    #[ORM\Column(length: PurchaseOrderLineDetails::REFERENCE_MAX)]
    private string $productReference;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $quantity;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(name: 'unit_id', nullable: false)]
    private Unit $unit;

    /** The net price the vendor is expected to ask for one unit; null when nobody said. */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4, nullable: true)]
    private ?string $unitPriceNet;

    /** What the receipts against the order brought of it so far. */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, options: ['default' => '0.000'])]
    private string $received = '0.000';

    /** @internal a line is written by PurchaseOrder */
    public function __construct(PurchaseOrder $order, int $position, PurchaseOrderLineDetails $details)
    {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->company = $order->getCompany();
        $this->position = $position;
        $this->productId = $details->productId;
        $this->productReference = $details->productReference;
        $this->description = $details->description;
        $this->quantity = $details->quantity;
        $this->unit = $details->unit;
        $this->unitPriceNet = $details->unitPriceNet;
    }

    /** @return array{string, string, string, string, string, string|null} what two lines are compared on */
    public function values(): array
    {
        return [$this->productId->toRfc4122(), $this->productReference, $this->description, $this->quantity, $this->unit->getId()->toRfc4122(), $this->unitPriceNet];
    }

    /** What has not arrived yet; never below zero. */
    public function outstanding(): string
    {
        $left = Decimal::of($this->quantity)->sub(Decimal::of($this->received));

        return $left->compare(0) <= 0 ? '0.000' : Decimal::format($left, PurchaseOrderLineDetails::QUANTITY_DECIMALS);
    }

    /** @internal a receipt is recorded by PurchaseOrder, which checked the quantity against what is outstanding */
    public function receive(string $quantity): void
    {
        $this->received = Decimal::format(Decimal::of($this->received)->add(Decimal::of($quantity)), PurchaseOrderLineDetails::QUANTITY_DECIMALS);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrder(): PurchaseOrder
    {
        return $this->order;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getProductId(): Uuid
    {
        return $this->productId;
    }

    public function getProductReference(): string
    {
        return $this->productReference;
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

    public function getUnitPriceNet(): ?string
    {
        return $this->unitPriceNet;
    }

    public function getReceived(): string
    {
        return $this->received;
    }
}
