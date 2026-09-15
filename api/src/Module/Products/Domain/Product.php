<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use App\Fiscal\Domain\Unit;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Something a company sells (docs/SPEC.md § 4 product). Its reference is the company's to choose and unique in it;
 * its unit and its category are the company's too. Documents will name a product, so a product is deactivated,
 * never deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product')]
#[ORM\Index(name: 'idx_product_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_product_unit', columns: ['unit_id'])]
#[ORM\Index(name: 'idx_product_category', columns: ['category_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_company_reference', columns: ['company_id', 'reference'])]
class Product implements CompanyOwned
{
    public const string REFERENCE = '/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,31}$/';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\Column(length: ProductDetails::NAME_MAX)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 16, enumType: ProductKind::class)]
    private ProductKind $kind;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(name: 'unit_id', nullable: false)]
    private Unit $unit;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $unitPriceNet;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4, nullable: true)]
    private ?string $costPrice = null;

    #[ORM\ManyToOne(targetEntity: ProductCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true)]
    private ?ProductCategory $category = null;

    #[ORM\Column(length: ProductDetails::BARCODE_MAX, nullable: true)]
    private ?string $barcode = null;

    /** @var list<string> the company's tax components a new line for this product starts with */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $defaultTaxComponentIds = [];

    /** @var array<string, string|int|float|bool> values by the company's custom field keys, checked by ManageProducts */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $customFields = [];

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @param list<Uuid> $defaultTaxComponentIds
     *
     * @throws InvalidProduct
     */
    public static function create(Company $company, string $reference, ProductDetails $details, Unit $unit, ?ProductCategory $category, array $defaultTaxComponentIds, \DateTimeImmutable $now): self
    {
        $product = new self($company, $now);
        $product->reference = self::reference($reference);
        $product->apply($details);
        $product->unit = $product->unitOfThisCompany($unit);
        $product->category = $product->categoryOfThisCompany($category);
        $product->defaultTaxComponentIds = self::ids($defaultTaxComponentIds);

        return $product;
    }

    /**
     * @param list<Uuid> $defaultTaxComponentIds
     *
     * @return list<string> the fields that changed, none when the revision says what the product already says
     *
     * @throws InvalidProduct
     */
    public function revise(string $reference, ProductDetails $details, Unit $unit, ?ProductCategory $category, array $defaultTaxComponentIds, bool $isActive, \DateTimeImmutable $now): array
    {
        $reference = self::reference($reference);
        $unit = $this->unitOfThisCompany($unit);
        $category = $this->categoryOfThisCompany($category);
        $ids = self::ids($defaultTaxComponentIds);

        $changed = $reference === $this->reference ? [] : ['reference'];
        $changed = [...$changed, ...$details->differencesFrom($this->getDetails())];
        if (!$unit->getId()->equals($this->unit->getId())) {
            $changed[] = 'unitId';
        }
        if ($category?->getId()->toRfc4122() !== $this->category?->getId()->toRfc4122()) {
            $changed[] = 'categoryId';
        }
        if ($ids !== $this->defaultTaxComponentIds) {
            $changed[] = 'defaultTaxComponentIds';
        }
        if ($isActive !== $this->isActive) {
            $changed[] = 'isActive';
        }
        if ([] === $changed) {
            return [];
        }

        $this->reference = $reference;
        $this->apply($details);
        $this->unit = $unit;
        $this->category = $category;
        $this->defaultTaxComponentIds = $ids;
        $this->isActive = $isActive;
        $this->updatedAt = $now;

        return $changed;
    }

    /**
     * @param array<string, string|int|float|bool> $values
     *
     * @return list<string> the fields that changed, named `customFields.<key>`
     */
    public function reviseCustomFields(array $values, \DateTimeImmutable $now): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($this->customFields), ...array_keys($values)]) as $key) {
            if (($this->customFields[$key] ?? null) !== ($values[$key] ?? null)) {
                $changed[] = 'customFields.'.$key;
            }
        }
        if ([] !== $changed) {
            $this->customFields = $values;
            $this->updatedAt = $now;
        }

        return $changed;
    }

    public function getDetails(): ProductDetails
    {
        return new ProductDetails($this->name, $this->description, $this->kind, $this->unitPriceNet, $this->costPrice, $this->barcode);
    }

    private function apply(ProductDetails $details): void
    {
        $this->name = $details->name;
        $this->description = $details->description;
        $this->kind = $details->kind;
        $this->unitPriceNet = $details->unitPriceNet;
        $this->costPrice = $details->costPrice;
        $this->barcode = $details->barcode;
    }

    private function unitOfThisCompany(Unit $unit): Unit
    {
        if (!$unit->getCompany()->getId()->equals($this->company->getId())) {
            throw new InvalidProduct('unitId', 'A product is sold in a unit of its own company.');
        }

        return $unit;
    }

    private function categoryOfThisCompany(?ProductCategory $category): ?ProductCategory
    {
        if (null !== $category && !$category->getCompany()->getId()->equals($this->company->getId())) {
            throw new InvalidProduct('categoryId', 'A product belongs to a category of its own company.');
        }

        return $category;
    }

    private static function reference(string $reference): string
    {
        $reference = trim($reference);
        if (1 !== preg_match(self::REFERENCE, $reference)) {
            throw new InvalidProduct('reference', \sprintf('"%s" is not a product reference: 1 to 32 letters, digits, dots, dashes, slashes or underscores.', $reference));
        }

        return $reference;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids)));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function getCategory(): ?ProductCategory
    {
        return $this->category;
    }

    /** @return list<string> */
    public function getDefaultTaxComponentIds(): array
    {
        return $this->defaultTaxComponentIds;
    }

    /** @return array<string, string|int|float|bool> */
    public function getCustomFields(): array
    {
        return $this->customFields;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
