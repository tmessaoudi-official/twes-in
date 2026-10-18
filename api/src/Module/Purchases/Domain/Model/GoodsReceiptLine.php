<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\Model;

use App\Module\Purchases\Domain\ValueObject\ReceivedGoods;
use App\Shared\Domain\Service\CompanyOwned;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What arrived of one ordered line in one place: how much, under which lot when the goods carry one, and what one unit
 * cost. One ordered line may come in several of these in one receipt, a lot or a place each. The place is held by its id:
 * the stock is its own module, which the receipt asks to put the goods there.
 */
#[ORM\Entity]
#[ORM\Table(name: 'goods_receipt_line')]
#[ORM\Index(name: 'idx_goods_receipt_line_receipt', columns: ['goods_receipt_id'])]
#[ORM\Index(name: 'idx_goods_receipt_line_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_goods_receipt_line_order_line', columns: ['purchase_order_line_id'])]
#[ORM\Index(name: 'idx_goods_receipt_line_location', columns: ['location_id'])]
class GoodsReceiptLine implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GoodsReceipt::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'goods_receipt_id', nullable: false, onDelete: 'CASCADE')]
    private GoodsReceipt $receipt;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(targetEntity: PurchaseOrderLine::class)]
    #[ORM\JoinColumn(name: 'purchase_order_line_id', nullable: false)]
    private PurchaseOrderLine $orderLine;

    #[ORM\Column(type: 'uuid')]
    private Uuid $locationId;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $quantity;

    #[ORM\Column(length: ReceivedGoods::LOT_CODE_MAX, nullable: true)]
    private ?string $lotCode;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lotExpiresOn;

    /** What one unit cost, net; null when the person receiving could not say and it is left to complete. */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4, nullable: true)]
    private ?string $unitCost;

    /** @internal a line is written by GoodsReceipt */
    public function __construct(GoodsReceipt $receipt, int $position, PurchaseOrderLine $orderLine, ReceivedGoods $goods)
    {
        $this->id = Uuid::v7();
        $this->receipt = $receipt;
        $this->company = $receipt->getCompany();
        $this->position = $position;
        $this->orderLine = $orderLine;
        $this->locationId = $goods->locationId;
        $this->quantity = $goods->quantity;
        $this->lotCode = $goods->lotCode;
        $this->lotExpiresOn = $goods->lotExpiresOn;
        $this->unitCost = $goods->unitCost;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReceipt(): GoodsReceipt
    {
        return $this->receipt;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getOrderLine(): PurchaseOrderLine
    {
        return $this->orderLine;
    }

    public function getLocationId(): Uuid
    {
        return $this->locationId;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getLotCode(): ?string
    {
        return $this->lotCode;
    }

    public function getLotExpiresOn(): ?\DateTimeImmutable
    {
        return $this->lotExpiresOn;
    }

    public function getUnitCost(): ?string
    {
        return $this->unitCost;
    }
}
