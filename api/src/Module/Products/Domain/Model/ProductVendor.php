<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain\Model;

use App\Module\Vendors\Domain\Model\Vendor;
use App\Shared\Domain\Service\CompanyOwned;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A vendor the product is bought from, with or without a code of theirs: the code, when there is one, stays a supplier
 * barcode, which is what a scan and an order to that vendor read. Written only through its product, which keeps every
 * supplier code's vendor linked.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_vendor')]
#[ORM\Index(name: 'idx_product_vendor_vendor', columns: ['vendor_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_vendor_pair', columns: ['product_id', 'vendor_id'])]
class ProductVendor implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'vendors')]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', nullable: false)]
    private Vendor $vendor;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Product $product, Vendor $vendor, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $product->getCompany();
        $this->product = $product;
        $this->vendor = $vendor;
        $this->createdAt = $now;
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

    public function getVendor(): Vendor
    {
        return $this->vendor;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
