<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Inventory\Domain\NamedLot;
use App\Module\Inventory\Domain\ReceiptDocument;
use App\Module\Inventory\Domain\StockLot;
use App\Module\Inventory\Domain\StockMovement;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Stock movements, never changed once written. Listed newest first with stock.read, the latest of the company or, with
 * `?productId=`, of one product. Written with stock.write by a person: `receive` adds the quantity at the location,
 * `count` records the difference between the quantity found and the stock there, even none, and `move` takes goods to
 * `toLocationId` inside the same establishment, writing the pair that left and arrived. Only goods whose stock is kept
 * move; a refusal answers 422 naming the field.
 */
#[ApiResource(
    shortName: 'StockMovement',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-movements',
            outputFormats: ['jsonld' => ['application/ld+json']],
            provider: StockMovementCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'q' => new QueryParameter(description: "Words found in the product's reference or name or the location's code or name."),
                'productId' => new QueryParameter(schema: self::ID, description: 'Only what moved this product.'),
                'locationId' => new QueryParameter(schema: self::ID, description: 'Only what moved at this location.'),
                'kind' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['in', 'out', 'adjustment']], description: 'Only what moved this way.'),
                'lot' => new QueryParameter(description: 'Only what moved this lot or serial number, its code matched whole and whatever its case.'),
                'sourceType' => new QueryParameter(schema: ['type' => 'string', 'enum' => [StockMovement::SOURCE_RECEIPT, StockMovement::SOURCE_COUNT, StockMovement::SOURCE_MOVE, StockMovement::SOURCE_LOSS, StockMovement::SOURCE_DELIVERY_NOTE, StockMovement::SOURCE_INVOICE]], description: 'Only what this kind of document moved.'),
                'order[movedAt]' => new QueryParameter(schema: self::DIRECTION),
                'order[product]' => new QueryParameter(schema: self::DIRECTION),
                'order[location]' => new QueryParameter(schema: self::DIRECTION),
                'order[kind]' => new QueryParameter(schema: self::DIRECTION),
                'order[quantity]' => new QueryParameter(schema: self::DIRECTION),
                'order[source]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/stock-movements',
            processor: RecordStockMovementProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class StockMovementResource
{
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];
    /** The reasons a loss may name; `StockLossReason` is the truth, and a test keeps the two equal. */
    public const array REASONS = ['lost', 'broken', 'expired', 'stolen', 'internal_use', 'sample'];
    private const array ID = ['type' => 'string', 'format' => 'uuid'];

    public const string READ = 'stock_movement:read';
    public const string WRITE = 'stock_movement:write';
    public const string RECEIVE = 'receive';
    public const string COUNT = 'count';
    public const string MOVE = 'move';
    public const string LOSS = 'loss';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** What a person records: goods received, a count of what is there, a move to another location, or a loss. */
    #[ApiProperty(schema: ['type' => 'string', 'enum' => [self::RECEIVE, self::COUNT, self::MOVE, self::LOSS]])]
    #[Assert\Choice(choices: [self::RECEIVE, self::COUNT, self::MOVE, self::LOSS], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $operation = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $productId = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $locationId = '';

    /**
     * Where a move puts the goods; only a move has one. Read back as null, because a movement happens AT one location:
     * the other half of the pair is the one that says where they arrived.
     */
    #[ApiProperty(schema: ['type' => 'string', 'format' => 'uuid', 'nullable' => true])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Assert\When(expression: 'this.operation === "'.self::MOVE.'"', constraints: [new Assert\NotBlank()], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $toLocationId = null;

    /**
     * The lot the goods belong to, for a product tracked by lot or serial number, and none otherwise (docs/SPEC.md
     * § 7, 2026-09-23 02:40): a receipt or a count opens it the first time its code is seen, a move names one there.
     */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => StockLot::CODE_MAX])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $lotCode = null;

    /** The date the lot's goods are used by, as its label says; given once, and filled in if the lot had none. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $lotExpiresOn = null;

    /** Why the goods were written off, for a loss alone; read back on that movement and null on every other. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'enum' => [...self::REASONS, null]])]
    #[Assert\Choice(choices: self::REASONS, groups: [self::WRITE])]
    #[Assert\When(expression: 'this.operation === "'.self::LOSS.'"', constraints: [new Assert\NotBlank()], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $reason = null;

    /** What the person said about the loss, at most 500 characters. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => StockMovement::NOTE_MAX])]
    #[Assert\Length(max: StockMovement::NOTE_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $note = null;

    /**
     * The vendor a receipt came from, the number on the vendor's own delivery note or invoice, and the day the goods
     * arrived: all optional, and only a receipt carries them. A vendor is named while Vendors is switched on; the day
     * is never one still to come.
     */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Assert\When(expression: 'this.operation !== "'.self::RECEIVE.'"', constraints: [new Assert\IsNull(message: 'Only a receipt names a vendor.')], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $vendorId = null;

    /** The vendor's name, read back so a list says whose goods they were without holding the vendors. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $vendorName = null;

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => ReceiptDocument::REFERENCE_MAX])]
    #[Assert\Length(max: ReceiptDocument::REFERENCE_MAX, groups: [self::WRITE])]
    #[Assert\When(expression: 'this.operation !== "'.self::RECEIVE.'"', constraints: [new Assert\IsNull(message: 'Only a receipt carries a supplier reference.')], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $supplierReference = null;

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Assert\When(expression: 'this.operation !== "'.self::RECEIVE.'"', constraints: [new Assert\IsNull(message: 'Only a receipt has a day of arrival.')], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $receivedOn = null;

    /**
     * For a receipt, where the company's setting offers the choice: the figure to make the product's cost, the weighted
     * average of what came in or the cost typed on this receipt. Ignored where the setting decides, and for a writer
     * who cannot read costs. Written only.
     */
    #[ApiProperty(readable: false, schema: ['type' => ['string', 'null'], 'enum' => ['average', 'last', null]])]
    #[Assert\Choice(choices: ['average', 'last'], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $applyCost = null;

    /**
     * What one unit cost, for a receipt: the stock is valued at the weighted average of what came in. Left out, the
     * receipt is valued at the average of what the product's stock already is, or at its cost price. Written only; the
     * valuation, read with product.cost.read, is where costs are shown.
     */
    #[ApiProperty(readable: false, schema: ['type' => ['string', 'null'], 'maxLength' => 16])]
    #[Groups([self::WRITE])]
    public ?string $unitCost = null;

    /**
     * What the movement moved, named here rather than looked up elsewhere: a product or a location the company has
     * since stopped offering still has its movements, and a list of them has to say whose they are.
     */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $productReference = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $productName = '';

    /**
     * The unit the product is counted in and how many decimals it counts, so a row shows its quantity the way the
     * unit is counted without the screen holding the whole catalogue (docs/SPEC.md § 7, 2026-09-17, ruling 3).
     */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $unitCode = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $unitDecimals = 3;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $locationCode = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $locationName = '';

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => ['in', 'out', 'adjustment']])]
    #[Groups([self::READ])]
    public string $kind = '';

    /** Written: the quantity received or found, in the product's unit. Read: signed, with three decimals. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $quantity = '';

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => [StockMovement::SOURCE_RECEIPT, StockMovement::SOURCE_COUNT, StockMovement::SOURCE_MOVE, StockMovement::SOURCE_LOSS, StockMovement::SOURCE_DELIVERY_NOTE, StockMovement::SOURCE_INVOICE]])]
    #[Groups([self::READ])]
    public string $sourceType = '';

    /** The document that moved the goods: a delivery note's id; null for what a person recorded. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $sourceId = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $recordedBy = null;

    /** When it moved, ISO 8601. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $at = '';

    /** Whether somebody typed what one unit cost, as opposed to the average a receipt without one is valued at. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $costTyped = false;

    /**
     * A receipt recorded by someone who could not read costs, whose cost a cost reader is asked to enter (docs/SPEC.md
     * § 7, audit 2026-10-06 C challenge 9).
     */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $costToComplete = false;

    /** The lot the write names, or none. */
    public function lot(): ?NamedLot
    {
        return null === $this->lotCode ? null : new NamedLot($this->lotCode, null === $this->lotExpiresOn ? null : new \DateTimeImmutable($this->lotExpiresOn));
    }

    public static function of(StockMovement $movement): self
    {
        $resource = new self();
        $resource->id = $movement->getId()->toRfc4122();
        $resource->productId = $movement->getProduct()->getId()->toRfc4122();
        $resource->productReference = $movement->getProduct()->getReference();
        $resource->productName = $movement->getProduct()->getDetails()->name;
        $resource->unitCode = $movement->getProduct()->getUnit()->getCode();
        $resource->unitDecimals = $movement->getProduct()->getUnit()->getDecimals();
        $resource->locationId = $movement->getLocation()->getId()->toRfc4122();
        $resource->locationCode = $movement->getLocation()->getCode();
        $resource->locationName = $movement->getLocation()->getName();
        $resource->lotCode = $movement->getLot()?->getCode();
        $resource->lotExpiresOn = $movement->getLot()?->getExpiresOn()?->format('Y-m-d');
        $resource->kind = $movement->getKind()->value;
        $resource->quantity = $movement->getQuantity();
        $resource->sourceType = $movement->getSourceType();
        $resource->reason = $movement->getReason()?->value;
        $resource->note = $movement->getNote();
        $resource->vendorId = $movement->getVendor()?->getId()->toRfc4122();
        $resource->vendorName = $movement->getVendor()?->getProfile()->name;
        $resource->supplierReference = $movement->getSupplierReference();
        $resource->receivedOn = $movement->getReceivedOn()?->format('Y-m-d');
        $resource->sourceId = $movement->getSourceId()?->toRfc4122();
        $resource->recordedBy = $movement->getRecordedBy()?->toRfc4122();
        $resource->at = $movement->getAt()->format(\DATE_ATOM);
        $resource->costTyped = $movement->isCostTyped();
        $resource->costToComplete = $movement->isCostToComplete();

        return $resource;
    }
}
