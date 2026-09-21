<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use App\Module\Products\Domain\Product;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Where a product normally lives, in one establishment (docs/SPEC.md row 101): the shelf a receipt proposes, so a
 * person putting goods away is not asked the same question every time. One per product per establishment, and
 * nullable in the sense that most products have none — a home is a convenience, never a rule about where stock may
 * sit, and nothing refuses a movement to another location.
 *
 * The establishment is stored beside the location rather than read through it, because it is what the uniqueness is
 * about: a product has one home in Tunis and another in Sfax, and a database cannot enforce that over a column it
 * has to join to find. The two are kept in step by the constructor, which is the only way one is made.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_home_location')]
#[ORM\Index(name: 'idx_product_home_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_product_home_location', columns: ['location_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_home_establishment', columns: ['product_id', 'establishment_id'])]
class ProductHomeLocation implements CompanyOwned
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

    /** What the uniqueness is about; always the location's own, kept so by `moveTo`. */
    #[ORM\ManyToOne(targetEntity: Establishment::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false, onDelete: 'CASCADE')]
    private Establishment $establishment;

    /**
     * Deleted with the location rather than left pointing at nothing: a home whose shelf is gone is not a home, and
     * the location itself refuses to go while it holds stock or children, so this cannot quietly lose a real place.
     */
    #[ORM\ManyToOne(targetEntity: StockLocation::class)]
    #[ORM\JoinColumn(name: 'location_id', nullable: false, onDelete: 'CASCADE')]
    private StockLocation $location;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Product $product, StockLocation $location, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $location->getCompany();
        $this->product = $product;
        $this->establishment = $location->getEstablishment();
        $this->location = $location;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @throws InvalidStockLocation when the product is not this company's */
    public static function at(Product $product, StockLocation $location, \DateTimeImmutable $now): self
    {
        if (!$product->getCompany()->getId()->equals($location->getCompany()->getId())) {
            throw new InvalidStockLocation('productId', 'A product is given a home among its own company’s locations.');
        }

        return new self($product, $location, $now);
    }

    /**
     * @return bool whether anything changed
     *
     * @throws InvalidStockLocation when the new location is in another establishment, which would be a second home
     */
    public function moveTo(StockLocation $location, \DateTimeImmutable $now): bool
    {
        if (!$location->getEstablishment()->getId()->equals($this->establishment->getId())) {
            throw new InvalidStockLocation('locationId', 'A home moves within its establishment; another establishment’s home is its own.');
        }
        if ($location->getId()->equals($this->location->getId())) {
            return false;
        }
        $this->location = $location;
        $this->updatedAt = $now;

        return true;
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

    public function getEstablishment(): Establishment
    {
        return $this->establishment;
    }

    public function getLocation(): StockLocation
    {
        return $this->location;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
