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
 * The quantity at or under which a product is to be reordered in one establishment (docs/SPEC.md § 7, 2026-09-24
 * 11:40): one per product per establishment, and most products have none, which means no alert. Counted in the
 * product's unit, never finer than it counts; zero is a real point, reordering once none is left. What reads it — the
 * alert, the "what to reorder" report and the digest — is theirs; this only keeps the number.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_reorder_point')]
#[ORM\Index(name: 'idx_product_reorder_point_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_product_reorder_point_establishment', columns: ['establishment_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_reorder_point', columns: ['product_id', 'establishment_id'])]
class ProductReorderPoint implements CompanyOwned
{
    private const string QUANTITY = '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Establishment::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false, onDelete: 'CASCADE')]
    private Establishment $establishment;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $quantity;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Product $product, Establishment $establishment, string $quantity, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $product->getCompany();
        $this->product = $product;
        $this->establishment = $establishment;
        $this->quantity = $quantity;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @throws InvalidReorderPoint */
    public static function of(Product $product, Establishment $establishment, string $quantity, \DateTimeImmutable $now): self
    {
        if (!$product->getCompany()->getId()->equals($establishment->getCompany()->getId())) {
            throw new InvalidReorderPoint('establishmentId', 'A reorder point is kept in one of the product’s own company’s establishments.');
        }

        return new self($product, $establishment, self::quantity($product, $quantity), $now);
    }

    /**
     * @return bool whether anything changed
     *
     * @throws InvalidReorderPoint
     */
    public function change(string $quantity, \DateTimeImmutable $now): bool
    {
        $quantity = self::quantity($this->product, $quantity);
        if ($quantity === $this->quantity) {
            return false;
        }
        $this->quantity = $quantity;
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

    /** A decimal string with three decimals, in the product's unit. */
    public function getQuantity(): string
    {
        return $this->quantity;
    }

    /** @throws InvalidReorderPoint */
    private static function quantity(Product $product, string $quantity): string
    {
        $quantity = trim($quantity);
        if (1 !== preg_match(self::QUANTITY, $quantity)) {
            throw new InvalidReorderPoint('quantity', 'A reorder point is a quantity from 0, with at most three decimals.');
        }
        [$units, $decimals] = [...explode('.', $quantity), ''];
        $unit = $product->getUnit();
        if (\strlen(rtrim($decimals, '0')) > $unit->getDecimals()) {
            throw new InvalidReorderPoint('quantity', \sprintf('The unit %s counts with %d decimals.', $unit->getCode(), $unit->getDecimals()));
        }

        return $units.'.'.str_pad($decimals, 3, '0');
    }
}
