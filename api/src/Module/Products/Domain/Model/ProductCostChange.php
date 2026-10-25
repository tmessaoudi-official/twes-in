<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain\Model;

use App\Module\Products\Domain\ValueObject\CostChangeSource;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Shared\Domain\Service\CompanyOwned;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One change of what a product costs the company, kept for good: the cost before and after, what moved it and who. Rows
 * are only ever added, so a product's history reads as the sequence of its costs. A past purchase read from a file is a
 * row of the same history that changes nothing: what one line of it cost, from which vendor, on which day when it is
 * known, on which invoice and how many.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_cost_change')]
#[ORM\Index(name: 'idx_product_cost_change_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_product_cost_change_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_product_cost_change_vendor', columns: ['vendor_id'])]
class ProductCostChange implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    /** @var numeric-string|null */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4, nullable: true)]
    private ?string $oldCost;

    /** @var numeric-string|null */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4, nullable: true)]
    private ?string $newCost;

    #[ORM\Column(length: 16, enumType: CostChangeSource::class)]
    private CostChangeSource $source;

    /** The receipt, for a cost a receipt applied; none otherwise. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $sourceId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $changedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $at;

    /** The vendor a past purchase was bought from; none for a change of the cost itself. */
    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', nullable: true)]
    private ?Vendor $vendor = null;

    /** The day a past purchase was made, when its file gives one that can be. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $purchasedOn = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $invoiceNumber = null;

    /** @var numeric-string|null how many a past purchase was of, a return below zero */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $quantity = null;

    /**
     * @param numeric-string|null $oldCost
     * @param numeric-string|null $newCost
     */
    public function __construct(Product $product, ?string $oldCost, ?string $newCost, CostChangeSource $source, ?Uuid $sourceId, ?Uuid $changedBy, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $product->getCompany();
        $this->product = $product;
        $this->oldCost = $oldCost;
        $this->newCost = $newCost;
        $this->source = $source;
        $this->sourceId = $sourceId;
        $this->changedBy = $changedBy;
        $this->at = $now;
    }

    /**
     * A past purchase of the product, read from the import run that names it: its unit cost as the cost after, nothing
     * before, since it changed nothing.
     *
     * @param numeric-string      $unitCost
     * @param numeric-string|null $quantity
     */
    public static function purchased(Product $product, Vendor $vendor, ?\DateTimeImmutable $purchasedOn, ?string $invoiceNumber, ?string $quantity, string $unitCost, Uuid $runId, ?Uuid $by, \DateTimeImmutable $now): self
    {
        $change = new self($product, null, $unitCost, CostChangeSource::Purchase, $runId, $by, $now);
        $change->vendor = $vendor;
        $change->purchasedOn = $purchasedOn;
        $change->invoiceNumber = $invoiceNumber;
        $change->quantity = $quantity;

        return $change;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    /** @return numeric-string|null */
    public function getOldCost(): ?string
    {
        return $this->oldCost;
    }

    /** @return numeric-string|null */
    public function getNewCost(): ?string
    {
        return $this->newCost;
    }

    public function getSource(): CostChangeSource
    {
        return $this->source;
    }

    public function getSourceId(): ?Uuid
    {
        return $this->sourceId;
    }

    public function getChangedBy(): ?Uuid
    {
        return $this->changedBy;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function getVendor(): ?Vendor
    {
        return $this->vendor;
    }

    public function getPurchasedOn(): ?\DateTimeImmutable
    {
        return $this->purchasedOn;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    /** @return numeric-string|null */
    public function getQuantity(): ?string
    {
        return $this->quantity;
    }
}
