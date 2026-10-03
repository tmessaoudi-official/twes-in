<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One change of what a product costs the company, kept for good: the cost before and after, what moved it and who. Rows
 * are only ever added, so a product's history reads as the sequence of its costs.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_cost_change')]
#[ORM\Index(name: 'idx_product_cost_change_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_product_cost_change_product', columns: ['product_id'])]
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
}
