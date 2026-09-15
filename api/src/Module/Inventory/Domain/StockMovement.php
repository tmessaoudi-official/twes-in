<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Goods moving in or out of a location (docs/SPEC.md § 4 stock_movement), never changed once written: the stock of a
 * product at a location is the sum of its movements. The quantity is signed, in the product's unit, kept with three
 * decimals and never finer than the unit counts. A movement says where it came from: a receipt or a count someone
 * recorded, or a delivery note. A delivery note moves a product from a location at most once each way, which the
 * unique source key holds even if its event arrives twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_movement')]
#[ORM\Index(name: 'idx_stock_movement_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_stock_movement_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_stock_movement_location', columns: ['location_id'])]
#[ORM\UniqueConstraint(name: 'uniq_stock_movement_source', columns: ['source_type', 'source_id', 'product_id', 'location_id', 'kind'])]
class StockMovement
{
    public const string SOURCE_RECEIPT = 'receipt';
    public const string SOURCE_COUNT = 'count';
    public const string SOURCE_DELIVERY_NOTE = 'delivery_note';
    public const int QUANTITY_DECIMALS = 3;
    private const string QUANTITY = '/^(-?)(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,3}))?$/';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false)]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: StockLocation::class)]
    #[ORM\JoinColumn(name: 'location_id', nullable: false)]
    private StockLocation $location;

    #[ORM\Column(length: 16, enumType: StockMovementKind::class)]
    private StockMovementKind $kind;

    /** @var numeric-string */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $quantity;

    #[ORM\Column(length: 32)]
    private string $sourceType;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $sourceId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $recordedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $at;

    /**
     * @param numeric-string $quantity signed, with three decimals
     *
     * @throws InvalidStockMovement
     */
    private function __construct(Product $product, StockLocation $location, StockMovementKind $kind, string $quantity, string $sourceType, ?Uuid $sourceId, ?Uuid $recordedBy, \DateTimeImmutable $now)
    {
        if (ProductKind::Goods !== $product->getDetails()->kind) {
            throw new InvalidStockMovement('productId', \sprintf('The product %s is a service: only goods are kept in stock.', $product->getReference()));
        }
        if (!$location->getCompany()->getId()->equals($product->getCompany()->getId())) {
            throw new InvalidStockMovement('locationId', 'Goods move in a location of their own company.');
        }
        $this->id = Uuid::v7();
        $this->company = $product->getCompany();
        $this->product = $product;
        $this->location = $location;
        $this->kind = $kind;
        $this->quantity = $quantity;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->recordedBy = $recordedBy;
        $this->at = $now;
    }

    /** @throws InvalidStockMovement */
    public static function receipt(Product $product, StockLocation $location, string $quantity, ?Uuid $recordedBy, \DateTimeImmutable $now): self
    {
        return new self($product, $location, StockMovementKind::In, self::quantity($quantity, $product, false), self::SOURCE_RECEIPT, null, $recordedBy, $now);
    }

    /**
     * What a count found against the stock expected there: the difference, signed, recorded even when there is none.
     *
     * @param numeric-string $expected the stock at the location before the count, "12.000"
     *
     * @throws InvalidStockMovement
     */
    public static function count(Product $product, StockLocation $location, string $counted, string $expected, ?Uuid $recordedBy, \DateTimeImmutable $now): self
    {
        $difference = new Number(self::quantity($counted, $product, true))->sub(new Number($expected))->value;

        return new self($product, $location, StockMovementKind::Adjustment, $difference, self::SOURCE_COUNT, null, $recordedBy, $now);
    }

    /** @throws InvalidStockMovement */
    public static function delivery(Product $product, StockLocation $location, string $quantity, Uuid $deliveryNoteId, \DateTimeImmutable $now): self
    {
        $out = new Number(self::quantity($quantity, $product, false))->mul(-1)->value;

        return new self($product, $location, StockMovementKind::Out, $out, self::SOURCE_DELIVERY_NOTE, $deliveryNoteId, null, $now);
    }

    /** The goods a delivery took out, back where they were: its note was cancelled. */
    public static function returnOf(self $delivery, \DateTimeImmutable $now): self
    {
        if (StockMovementKind::Out !== $delivery->kind || self::SOURCE_DELIVERY_NOTE !== $delivery->sourceType || null === $delivery->sourceId) {
            throw new \LogicException('Only what a delivery note took out is returned.');
        }

        $back = new Number($delivery->quantity)->mul(-1)->value;

        return new self($delivery->product, $delivery->location, StockMovementKind::In, $back, self::SOURCE_DELIVERY_NOTE, $delivery->sourceId, null, $now);
    }

    /**
     * A quantity of the product, never negative, zero only when a count may find none; with three decimals.
     *
     * @return numeric-string
     */
    private static function quantity(string $quantity, Product $product, bool $zeroAllowed): string
    {
        if (1 !== preg_match(self::QUANTITY, trim($quantity), $match)) {
            throw new InvalidStockMovement('quantity', 'A quantity is a decimal number with at most eleven digits and three decimals.');
        }
        [, $sign, $units] = $match;
        $decimals = $match[3] ?? '';
        $unit = $product->getUnit();
        if (\strlen(rtrim($decimals, '0')) > $unit->getDecimals()) {
            throw new InvalidStockMovement('quantity', \sprintf('The unit %s counts with %d decimals.', $unit->getCode(), $unit->getDecimals()));
        }
        $zero = '' === trim($units.$decimals, '0');
        if (('-' === $sign && !$zero) || ($zero && !$zeroAllowed)) {
            throw new InvalidStockMovement('quantity', $zeroAllowed ? 'A count finds zero or more.' : 'Goods move by a positive quantity.');
        }

        $normalized = $units.'.'.str_pad($decimals, self::QUANTITY_DECIMALS, '0');
        if (!is_numeric($normalized)) {
            throw new \LogicException(\sprintf('The quantity %s was not normalized to a number.', $normalized));
        }

        return $normalized;
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

    public function getLocation(): StockLocation
    {
        return $this->location;
    }

    public function getKind(): StockMovementKind
    {
        return $this->kind;
    }

    /**
     * Signed, with three decimals: "-3.000" took three out.
     *
     * @return numeric-string
     */
    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): ?Uuid
    {
        return $this->sourceId;
    }

    public function getRecordedBy(): ?Uuid
    {
        return $this->recordedBy;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
