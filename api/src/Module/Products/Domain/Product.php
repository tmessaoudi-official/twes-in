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
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    /** How its stock is told apart; `none` until someone says otherwise, and only before its first movement. */
    #[ORM\Column(length: 8, enumType: ProductTracking::class, options: ['default' => 'none'])]
    private ProductTracking $tracking;

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

    /**
     * The codes it answers to (docs/SPEC.md § 7, 2026-09-22 11:05), written only through `replaceBarcodes`.
     *
     * @var Collection<int, ProductBarcode>
     */
    #[ORM\OneToMany(targetEntity: ProductBarcode::class, mappedBy: 'product', cascade: ['persist', 'detach'], orphanRemoval: true)]
    private Collection $barcodes;

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
        $this->barcodes = new ArrayCollection();
        // Set here, not as the property's default: Doctrine's lazy ghosts skip defaults naming another class.
        $this->tracking = ProductTracking::None;
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
     * The tracking a product of this kind may have: a service holds no stock, so it tracks nothing.
     *
     * @throws InvalidProduct
     */
    public static function trackingFor(ProductTracking $tracking, ProductKind $kind): ProductTracking
    {
        if (ProductTracking::None !== $tracking && ProductKind::Service === $kind) {
            throw new InvalidProduct('tracking', 'A service holds no stock, so it is tracked neither by lot nor by serial.');
        }

        return $tracking;
    }

    /**
     * Sets how its stock is told apart. Whether stock already moved is the use case's to know; this holds the rest.
     *
     * @return bool whether it changed
     *
     * @throws InvalidProduct
     */
    public function track(ProductTracking $tracking, \DateTimeImmutable $now): bool
    {
        self::trackingFor($tracking, $this->kind);
        if ($tracking === $this->tracking) {
            return false;
        }
        $this->tracking = $tracking;
        $this->updatedAt = $now;

        return true;
    }

    public function getTracking(): ProductTracking
    {
        return $this->tracking;
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

    /**
     * The codes the product answers to become exactly these (docs/SPEC.md § 7, 2026-09-22 11:05). A row whose code
     * stays is revised IN PLACE, never removed and added again: Doctrine inserts before it deletes, so the company's
     * unique key on the code would refuse the new row before the old one left. That a code is not held by ANOTHER
     * product is the use case's to check, since only it sees the company's other products.
     *
     * @param list<BarcodeLine> $lines
     *
     * @return bool whether anything changed
     *
     * @throws InvalidProduct naming the row at fault, `barcodes.<index>.<field>`
     */
    public function replaceBarcodes(array $lines, \DateTimeImmutable $now): bool
    {
        $wanted = [];
        foreach ($lines as $index => $line) {
            if (isset($wanted[$line->barcode->key])) {
                throw new InvalidProduct("barcodes.$index.code", \sprintf('The code %s is already on this product.', $line->barcode->code));
            }
            if (null !== $line->supplier && !$line->supplier->getCompany()->getId()->equals($this->company->getId())) {
                throw new InvalidProduct("barcodes.$index.supplierId", 'A product\'s supplier code names a supplier of its own company.');
            }
            $wanted[$line->barcode->key] = $line;
        }

        $changed = false;
        foreach ($this->barcodes as $row) {
            if (!isset($wanted[$row->getMatchKey()])) {
                $this->barcodes->removeElement($row);
                $changed = true;
            }
        }
        foreach ($wanted as $key => $line) {
            $row = $this->barcodes->findFirst(static fn (int $_, ProductBarcode $held): bool => $held->getMatchKey() === (string) $key);
            if (null === $row) {
                $this->barcodes->add(new ProductBarcode($this, $line, $now));
                $changed = true;
            } elseif ($row->write($line)) {
                $changed = true;
            }
        }
        if ($changed) {
            $this->updatedAt = $now;
        }

        return $changed;
    }

    /** @return list<ProductBarcode> by role — unit, pack, supplier, internal — then by code */
    public function getBarcodes(): array
    {
        $rows = $this->barcodes->getValues();
        usort($rows, static fn (ProductBarcode $a, ProductBarcode $b): int => [$a->getRole()->rank(), $a->getCode()] <=> [$b->getRole()->rank(), $b->getCode()]);

        return $rows;
    }

    public function getDetails(): ProductDetails
    {
        return new ProductDetails($this->name, $this->description, $this->kind, $this->unitPriceNet, $this->costPrice);
    }

    private function apply(ProductDetails $details): void
    {
        $this->name = $details->name;
        $this->description = $details->description;
        $this->kind = $details->kind;
        $this->unitPriceNet = $details->unitPriceNet;
        $this->costPrice = $details->costPrice;
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
