<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Module\Vendors\Domain\Vendor;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use BcMath\Number;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Goods moving in or out of a location (docs/SPEC.md § 4 stock_movement), never changed once written: the stock of a
 * product at a location is the sum of its movements. The quantity is signed, in the product's unit, kept with three
 * decimals and never finer than the unit counts. A movement says where it came from: a receipt or a count someone
 * recorded, or a delivery note. A delivery note moves a product from a location at most once each way and lot, which
 * the unique source key holds even if its event arrives twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_movement')]
#[ORM\Index(name: 'idx_stock_movement_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_stock_movement_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_stock_movement_location', columns: ['location_id'])]
#[ORM\Index(name: 'idx_stock_movement_lot', columns: ['lot_id'])]
#[ORM\Index(name: 'idx_stock_movement_reverses', columns: ['reverses_source_id'], options: ['where' => '(reverses_source_id IS NOT NULL)'])]
#[ORM\Index(name: 'idx_stock_movement_vendor', columns: ['vendor_id'])]
#[ORM\UniqueConstraint(name: 'uniq_stock_movement_source', columns: ['source_type', 'source_id', 'product_id', 'location_id', 'kind', 'lot_id'], options: ['where' => '(source_id IS NOT NULL)'])]
class StockMovement implements CompanyOwned
{
    public const string SOURCE_RECEIPT = 'receipt';
    public const string SOURCE_COUNT = 'count';
    public const string SOURCE_DELIVERY_NOTE = 'delivery_note';
    public const string SOURCE_INVOICE = 'invoice';
    public const string SOURCE_CREDIT_NOTE = 'credit_note';
    public const string SOURCE_MOVE = 'move';
    public const string SOURCE_LOSS = 'loss';
    public const int NOTE_MAX = 500;
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

    /** The lot it moved, for a product tracked by lot or serial number; none otherwise (docs/SPEC.md § 7, 2026-09-23 02:40). */
    #[ORM\ManyToOne(targetEntity: StockLot::class)]
    #[ORM\JoinColumn(name: 'lot_id', nullable: true)]
    private ?StockLot $lot;

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

    /** Why goods were written off; set on a loss alone. */
    #[ORM\Column(length: 16, nullable: true, enumType: StockLossReason::class)]
    private ?StockLossReason $reason = null;

    #[ORM\Column(length: self::NOTE_MAX, nullable: true)]
    private ?string $note = null;

    /**
     * What one unit was valued at when it moved, four decimals; null while no cost is known for the product.
     *
     * @var numeric-string|null
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $unitCost = null;

    /**
     * What this movement adds to the stock's worth beyond quantity times cost: goods coming in to fill a stock that was
     * below nothing set its worth at their own cost (RunningValue). Seven decimals; null for every other movement.
     *
     * @var numeric-string|null
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 7, nullable: true)]
    private ?string $revaluation = null;

    /** Whether somebody typed the cost, as opposed to the average a movement without one is valued at. */
    #[ORM\Column(options: ['default' => false])]
    private bool $costTyped = false;

    /** A receipt recorded by someone who may not read costs, valued at the average until a cost reader enters its cost. */
    #[ORM\Column(options: ['default' => false])]
    private bool $costToComplete = false;

    /** The vendor a receipt came from, when it names one; the vendor kept, its movements keep it, a deleted one leaves none. */
    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', nullable: true, onDelete: 'SET NULL')]
    private ?Vendor $vendor = null;

    /** The number on the vendor's own delivery note or invoice, as the person read it. */
    #[ORM\Column(length: ReceiptDocument::REFERENCE_MAX, nullable: true)]
    private ?string $supplierReference = null;

    /** The day the goods arrived, which is not always the day somebody typed them in. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $receivedOn = null;

    /** The invoice whose sale this movement takes back, for the goods a credit note returned; none otherwise. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $reversesSourceId = null;

    /**
     * @param numeric-string $quantity signed, with three decimals
     *
     * @throws InvalidStockMovement
     */
    private function __construct(Product $product, StockLocation $location, ?StockLot $lot, StockMovementKind $kind, string $quantity, string $sourceType, ?Uuid $sourceId, ?Uuid $recordedBy, \DateTimeImmutable $now)
    {
        self::lotOf($product, $lot);
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
        $this->lot = $lot;
        $this->kind = $kind;
        $this->quantity = $quantity;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->recordedBy = $recordedBy;
        $this->at = $now;
    }

    /** @throws InvalidStockMovement */
    public static function receipt(Product $product, StockLocation $location, string $quantity, ?Uuid $recordedBy, \DateTimeImmutable $now, ?StockLot $lot = null, ?string $unitCost = null, ?ReceiptDocument $document = null, bool $costToComplete = false): self
    {
        $receipt = new self($product, $location, $lot, StockMovementKind::In, self::onePieceOfASerial($product, self::quantity($quantity, $product, false)), self::SOURCE_RECEIPT, null, $recordedBy, $now);
        if (null !== $unitCost) {
            $receipt->unitCost = self::cost($unitCost);
            $receipt->costTyped = true;
        }
        $receipt->costToComplete = $costToComplete && null === $unitCost;
        if (null !== $document) {
            // The company's own day decides what is still to come, wherever the server is.
            $today = $now->setTimezone(new \DateTimeZone($product->getCompany()->getTimezone()))->format('Y-m-d');
            if (null !== $document->receivedOn && $document->receivedOn->format('Y-m-d') > $today) {
                throw new InvalidStockMovement('receivedOn', 'Goods cannot have arrived on a day that has not come yet.');
            }
            $receipt->vendor = $document->vendor;
            $receipt->supplierReference = $document->supplierReference;
            $receipt->receivedOn = $document->receivedOn;
        }

        return $receipt;
    }

    /**
     * What a count found against the stock expected there: the difference, signed, recorded even when there is none.
     *
     * @param numeric-string $expected the stock at the location before the count, "12.000"
     *
     * @throws InvalidStockMovement
     */
    public static function count(Product $product, StockLocation $location, string $counted, string $expected, ?Uuid $recordedBy, \DateTimeImmutable $now, ?StockLot $lot = null): self
    {
        $found = self::quantity($counted, $product, true);
        if (ProductTracking::Serial === $product->getTracking() && 1 === new Number($found)->compare(1)) {
            throw new InvalidStockMovement('quantity', 'A serial number is one piece: a count finds it or not.');
        }
        $difference = new Number($found)->sub(new Number($expected))->value;

        return new self($product, $location, $lot, StockMovementKind::Adjustment, $difference, self::SOURCE_COUNT, null, $recordedBy, $now);
    }

    /** @throws InvalidStockMovement */
    public static function delivery(Product $product, StockLocation $location, string $quantity, Uuid $deliveryNoteId, \DateTimeImmutable $now, ?StockLot $lot = null): self
    {
        return self::goodsOut($product, $location, $quantity, self::SOURCE_DELIVERY_NOTE, $deliveryNoteId, $now, $lot);
    }

    /**
     * Goods an invoice sold that no delivery note handed over.
     *
     * @throws InvalidStockMovement
     */
    public static function sale(Product $product, StockLocation $location, string $quantity, Uuid $invoiceId, \DateTimeImmutable $now, ?StockLot $lot = null): self
    {
        return self::goodsOut($product, $location, $quantity, self::SOURCE_INVOICE, $invoiceId, $now, $lot);
    }

    /** @throws InvalidStockMovement */
    private static function goodsOut(Product $product, StockLocation $location, string $quantity, string $sourceType, Uuid $sourceId, \DateTimeImmutable $now, ?StockLot $lot): self
    {
        $out = new Number(self::quantity($quantity, $product, false))->mul(-1)->value;

        return new self($product, $location, $lot, StockMovementKind::Out, $out, $sourceType, $sourceId, null, $now);
    }

    /**
     * Goods taken out of one location and into another inside the same establishment (§ 7 2026-09-19 23:25), as ONE
     * operation: the two movements are made together and share a move id, so neither half can exist without the
     * other and a list can show them as the single move they are.
     *
     * Between establishments comes later, with the transport document it needs.
     *
     * @return array{self, self} what left, then what arrived
     *
     * @throws InvalidStockMovement
     */
    public static function move(Product $product, StockLocation $from, StockLocation $to, string $quantity, ?Uuid $recordedBy, \DateTimeImmutable $now, ?StockLot $lot = null): array
    {
        if ($from->getId()->equals($to->getId())) {
            throw new InvalidStockMovement('toLocationId', 'Goods already at a location have not moved: choose another one.');
        }
        if (!$from->getEstablishment()->getId()->equals($to->getEstablishment()->getId())) {
            throw new InvalidStockMovement('toLocationId', 'A move stays inside one establishment.');
        }
        $moved = self::onePieceOfASerial($product, self::quantity($quantity, $product, false));
        $moveId = Uuid::v7();

        return [
            new self($product, $from, $lot, StockMovementKind::Out, new Number($moved)->mul(-1)->value, self::SOURCE_MOVE, $moveId, $recordedBy, $now),
            new self($product, $to, $lot, StockMovementKind::In, $moved, self::SOURCE_MOVE, $moveId, $recordedBy, $now),
        ];
    }

    /**
     * Goods written off with the reason they left under (§ 7 2026-09-19 23:25): out of the stock with no document, so
     * the reason and an optional note are what a report has to tell a breakage from a theft.
     *
     * @throws InvalidStockMovement
     */
    public static function loss(Product $product, StockLocation $location, string $quantity, StockLossReason $reason, ?string $note, ?Uuid $recordedBy, \DateTimeImmutable $now, ?StockLot $lot = null): self
    {
        $note = null === $note ? null : trim($note);
        if (null !== $note && mb_strlen($note) > self::NOTE_MAX) {
            throw new InvalidStockMovement('note', \sprintf('A note is at most %d characters.', self::NOTE_MAX));
        }
        $lost = self::onePieceOfASerial($product, self::quantity($quantity, $product, false));
        $loss = new self($product, $location, $lot, StockMovementKind::Out, new Number($lost)->mul(-1)->value, self::SOURCE_LOSS, null, $recordedBy, $now);
        $loss->reason = $reason;
        $loss->note = '' === $note ? null : $note;

        return $loss;
    }

    /** The goods a delivery took out, back where they were: its note was cancelled. */
    public static function returnOf(self $delivery, \DateTimeImmutable $now): self
    {
        if (StockMovementKind::Out !== $delivery->kind || self::SOURCE_DELIVERY_NOTE !== $delivery->sourceType || null === $delivery->sourceId) {
            throw new \LogicException('Only what a delivery note took out is returned.');
        }

        $back = new Number($delivery->quantity)->mul(-1)->value;

        $return = new self($delivery->product, $delivery->location, $delivery->lot, StockMovementKind::In, $back, self::SOURCE_DELIVERY_NOTE, $delivery->sourceId, null, $now);
        // The goods come back worth what they left at, whatever the average has become since.
        $return->unitCost = $delivery->unitCost;

        return $return;
    }

    /**
     * Part of what an invoice sold, back where it left from: a credit note returned it. What it sold left through the
     * invoice itself or through the delivery notes it was built from (docs/SPEC.md § 7, audit 2026-10-06 E-5), so the
     * goods come back from every such sale of one product at one place and lot, worth what they left at weighed by
     * quantity, and name the invoice so what was already returned is counted against it. A credit note returns a product
     * at most once per location and lot, which the unique source key holds.
     *
     * @param non-empty-list<self> $sales what the invoice, or a delivery note it was built from, took out of one product at one place and lot
     *
     * @throws InvalidStockMovement when the quantity is not a positive number the unit counts, or is more than the sales took
     */
    public static function returnOfSales(array $sales, string $quantity, Uuid $creditNoteId, Uuid $invoiceId, \DateTimeImmutable $now): self
    {
        $first = $sales[0];
        $taken = new Number(0);
        $valued = new Number(0);
        $amount = new Number(0);
        foreach ($sales as $sale) {
            if (StockMovementKind::Out !== $sale->kind || !\in_array($sale->sourceType, [self::SOURCE_INVOICE, self::SOURCE_DELIVERY_NOTE], true) || null === $sale->sourceId) {
                throw new \LogicException('Only what an invoice or its delivery notes took out is returned by a credit note.');
            }
            if ($sale->product !== $first->product || $sale->location !== $first->location || $sale->lot !== $first->lot) {
                throw new \LogicException('A return comes back to one product at one place and lot.');
            }
            $out = new Number($sale->quantity)->mul(-1);
            $taken = $taken->add($out);
            if (null !== $sale->unitCost) {
                $valued = $valued->add($out);
                $amount = $amount->add($out->mul($sale->unitCost));
            }
        }
        $back = self::quantity($quantity, $first->product, false);
        if (1 === new Number($back)->compare($taken)) {
            throw new InvalidStockMovement('quantity', 'A sale is returned for no more than it took out.');
        }

        $return = new self($first->product, $first->location, $first->lot, StockMovementKind::In, $back, self::SOURCE_CREDIT_NOTE, $creditNoteId, null, $now);
        $return->unitCost = 1 === $valued->compare(0) ? $amount->div($valued, 10)->round(4)->value : null;
        $return->reversesSourceId = $invoiceId;

        return $return;
    }

    /**
     * A tracked product's movement names its lot, and an untracked one's names none: the table cannot hold this, since
     * it cannot see the product's tracking, so every movement is made through here.
     *
     * @throws InvalidStockMovement
     */
    private static function lotOf(Product $product, ?StockLot $lot): void
    {
        $tracked = ProductTracking::None !== $product->getTracking();
        if ($tracked && null === $lot) {
            throw new InvalidStockMovement('lot', \sprintf('The product %s is tracked by %s: say which one moved.', $product->getReference(), ProductTracking::Serial === $product->getTracking() ? 'serial number' : 'lot'));
        }
        if (!$tracked && null !== $lot) {
            throw new InvalidStockMovement('lot', \sprintf('The product %s is not tracked by lot or serial number: its stock names no lot.', $product->getReference()));
        }
        if (null !== $lot && $lot->getProduct() !== $product) {
            throw new InvalidStockMovement('lot', \sprintf('The lot %s is not one of %s.', $lot->getCode(), $product->getReference()));
        }
    }

    /**
     * @param numeric-string $quantity
     *
     * @return numeric-string
     *
     * @throws InvalidStockMovement
     */
    private static function onePieceOfASerial(Product $product, string $quantity): string
    {
        if (ProductTracking::Serial === $product->getTracking() && 0 !== new Number($quantity)->compare(1)) {
            throw new InvalidStockMovement('quantity', 'A serial number is one piece: it moves one at a time.');
        }

        return $quantity;
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

    public function getLot(): ?StockLot
    {
        return $this->lot;
    }

    public function getKind(): StockMovementKind
    {
        return $this->kind;
    }

    public function isCostTyped(): bool
    {
        return $this->costTyped;
    }

    public function isCostToComplete(): bool
    {
        return $this->costToComplete;
    }

    /**
     * A cost reader enters the cost of a receipt left « à compléter »: it is worth that from then on, and lifts a stock
     * that was below nothing as it would have at that cost.
     *
     * @param RunningValue $before the product's valued stock before this receipt
     *
     * @throws InvalidStockMovement
     * @throws StockMovementCostKnown
     */
    public function costEntered(string $unitCost, RunningValue $before): void
    {
        if (!$this->costToComplete) {
            throw new StockMovementCostKnown('Only a receipt whose cost was left to a cost reader takes one afterwards.');
        }
        $this->unitCost = self::cost($unitCost);
        $this->costTyped = true;
        $this->costToComplete = false;
        $this->revaluation = $before->revaluationFor($this->quantity, $this->unitCost);
    }

    /**
     * @return numeric-string
     *
     * @throws InvalidStockMovement
     */
    private static function cost(string $unitCost): string
    {
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,4}))?$/', trim($unitCost), $match)) {
            throw new InvalidStockMovement('unitCost', 'A cost is an amount from zero with at most four decimals.');
        }
        $normalized = $match[1].'.'.str_pad($match[2] ?? '', 4, '0');
        if (!is_numeric($normalized)) {
            throw new \LogicException(\sprintf('The cost %s was not normalized to a number.', $normalized));
        }

        return new Number($normalized)->value;
    }

    /** The invoice whose sale this movement takes back; none for any other movement. */
    public function getReversesSourceId(): ?Uuid
    {
        return $this->reversesSourceId;
    }

    /** Values a movement that came with no cost: what it moves is worth this much a unit. Never changes one that has it. */
    /** @param numeric-string $unitCost */
    public function valuedAt(string $unitCost): void
    {
        $this->unitCost ??= $unitCost;
    }

    /** @param numeric-string $amount */
    public function revaluedBy(string $amount): void
    {
        $this->revaluation ??= $amount;
    }

    /** @return numeric-string|null */
    public function getRevaluation(): ?string
    {
        return $this->revaluation;
    }

    public function getVendor(): ?Vendor
    {
        return $this->vendor;
    }

    public function getSupplierReference(): ?string
    {
        return $this->supplierReference;
    }

    public function getReceivedOn(): ?\DateTimeImmutable
    {
        return $this->receivedOn;
    }

    /** @return numeric-string|null */
    public function getUnitCost(): ?string
    {
        return $this->unitCost;
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

    public function getReason(): ?StockLossReason
    {
        return $this->reason;
    }

    public function getNote(): ?string
    {
        return $this->note;
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
