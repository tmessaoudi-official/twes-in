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
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Goods that arrived against an order on one day: the vendor's own number for the delivery when the paper gives one,
 * and what came, line by line. Recorded once and never changed: what reached the shelves is corrected by the stock.
 */
#[ORM\Entity]
#[ORM\Table(name: 'goods_receipt')]
#[ORM\Index(name: 'idx_goods_receipt_order', columns: ['purchase_order_id'])]
#[ORM\Index(name: 'idx_goods_receipt_company', columns: ['company_id'])]
class GoodsReceipt implements CompanyOwned
{
    public const int REFERENCE_MAX = 60;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'receipts')]
    #[ORM\JoinColumn(name: 'purchase_order_id', nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $order;

    /** The company's day the goods arrived. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $receivedOn;

    #[ORM\Column(length: self::REFERENCE_MAX, nullable: true)]
    private ?string $supplierReference;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $recordedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, GoodsReceiptLine> */
    #[ORM\OneToMany(targetEntity: GoodsReceiptLine::class, mappedBy: 'receipt', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /**
     * @internal a receipt is recorded by PurchaseOrder, which checked every line against what it ordered
     *
     * @param list<array{PurchaseOrderLine, ReceivedGoods}> $lines
     */
    public function __construct(PurchaseOrder $order, \DateTimeImmutable $receivedOn, ?string $supplierReference, array $lines, ?Uuid $recordedBy, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $order->getCompany();
        $this->order = $order;
        $this->receivedOn = $receivedOn;
        $this->supplierReference = $supplierReference;
        $this->recordedBy = $recordedBy;
        $this->createdAt = $now;
        $this->lines = new ArrayCollection();
        foreach ($lines as $position => [$orderLine, $goods]) {
            $this->lines->add(new GoodsReceiptLine($this, $position, $orderLine, $goods));
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getOrder(): PurchaseOrder
    {
        return $this->order;
    }

    public function getReceivedOn(): \DateTimeImmutable
    {
        return $this->receivedOn;
    }

    public function getSupplierReference(): ?string
    {
        return $this->supplierReference;
    }

    public function getRecordedBy(): ?Uuid
    {
        return $this->recordedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<GoodsReceiptLine> */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }
}
