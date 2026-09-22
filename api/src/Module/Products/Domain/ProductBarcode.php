<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use App\Module\Vendors\Domain\Vendor;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One code a product answers to (docs/SPEC.md § 7, 2026-09-22 11:05). Written only through its product, which keeps
 * the rules that span the list; unique across the company on its match key, every role together, because two rows
 * sharing a code would make a scan ambiguous, which is the one thing a scan may never be.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_barcode')]
#[ORM\Index(name: 'idx_product_barcode_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_product_barcode_supplier', columns: ['supplier_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_barcode_key', columns: ['company_id', 'match_key'])]
class ProductBarcode implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'barcodes')]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 16, enumType: BarcodeRole::class)]
    private BarcodeRole $role;

    #[ORM\Column(length: Barcode::MAX)]
    private string $code;

    #[ORM\Column(name: 'match_key', length: Barcode::MAX)]
    private string $matchKey;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: true)]
    private ?Vendor $supplier = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Product $product, BarcodeLine $line, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $product->getCompany();
        $this->product = $product;
        $this->createdAt = $now;
        $this->apply($line);
    }

    /** @return bool whether anything the line says differs from what the row held */
    public function write(BarcodeLine $line): bool
    {
        if ($this->role === $line->role
            && $this->code === $line->barcode->code
            && $this->quantity === $line->quantity
            && $this->supplier?->getId()->toRfc4122() === $line->supplier?->getId()->toRfc4122()) {
            return false;
        }
        $this->apply($line);

        return true;
    }

    private function apply(BarcodeLine $line): void
    {
        $this->role = $line->role;
        $this->code = $line->barcode->code;
        $this->matchKey = $line->barcode->key;
        $this->quantity = $line->quantity;
        $this->supplier = $line->supplier;
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

    public function getRole(): BarcodeRole
    {
        return $this->role;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMatchKey(): string
    {
        return $this->matchKey;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getSupplier(): ?Vendor
    {
        return $this->supplier;
    }
}
