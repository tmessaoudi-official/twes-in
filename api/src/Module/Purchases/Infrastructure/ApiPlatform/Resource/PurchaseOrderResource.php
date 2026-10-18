<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Purchases\Application\PurchaseOrderInput;
use App\Module\Purchases\Application\PurchaseOrderLineInput;
use App\Module\Purchases\Application\PurchaseOrderTotals;
use App\Module\Purchases\Domain\Model\GoodsReceipt;
use App\Module\Purchases\Domain\Model\GoodsReceiptLine;
use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderHeader;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderLineDetails;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Processor\CancelPurchaseOrderProcessor;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Processor\ClosePurchaseOrderProcessor;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Processor\CreatePurchaseOrderProcessor;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Processor\RevisePurchaseOrderProcessor;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Processor\SendPurchaseOrderProcessor;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider\PurchaseOrderCollectionProvider;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider\PurchaseOrderItemProvider;
use App\Shared\Infrastructure\ApiPlatform\Payload\RecordVersion;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's orders to its vendors. Read with purchase.read; drafted, revised, sent, closed and cancelled with
 * purchase.write; goods are received against one with purchase.receive (POST .../receipts). An order that is no longer
 * a draft answers 409 to a revision. What one unit should cost, and what a receipt says it cost, are costs: answered
 * null, and left aside when sent, for whoever lacks product.cost.read. Quantities carry three decimals, prices four, the
 * net amounts the currency's scale.
 */
#[ApiResource(
    shortName: 'PurchaseOrder',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/purchase-orders',
            provider: PurchaseOrderCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            // One page at a time, with its total, which only JSON-LD carries.
            outputFormats: ['jsonld' => ['application/ld+json']],
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'Words found in the number or the vendor\'s name, whatever their case and accents; under three characters, the exact number only.'),
                'status[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::STATUSES]], description: 'Several statuses, OR\'d; a single `status=draft` still works.', constraints: []),
                'vendorId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => self::ID], description: 'Several vendors, OR\'d; a single `vendorId=…` still works.', constraints: []),
                'issueDate[from]' => new QueryParameter(schema: self::DAY, description: 'Sent on or after this day. A draft has no issue day, so it is left out by any end.'),
                'issueDate[to]' => new QueryParameter(schema: self::DAY, description: 'Sent on or before this day.'),
                'expectedOn[from]' => new QueryParameter(schema: self::DAY, description: 'Expected on or after this day.'),
                'expectedOn[to]' => new QueryParameter(schema: self::DAY, description: 'Expected on or before this day.'),
                'order[number]' => new QueryParameter(schema: self::DIRECTION, description: 'Drafts carry no number and come last whichever the direction.'),
                'order[vendor]' => new QueryParameter(schema: self::DIRECTION),
                'order[issueDate]' => new QueryParameter(schema: self::DIRECTION),
                'order[expectedOn]' => new QueryParameter(schema: self::DIRECTION),
                'order[status]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
        new Get(
            uriTemplate: '/companies/{companyId}/purchase-orders/{purchaseOrderId}',
            provider: PurchaseOrderItemProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/purchase-orders',
            processor: CreatePurchaseOrderProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/purchase-orders/{purchaseOrderId}',
            processor: RevisePurchaseOrderProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
            validationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/purchase-orders/{purchaseOrderId}/send',
            status: 200,
            processor: SendPurchaseOrderProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/purchase-orders/{purchaseOrderId}/close',
            status: 200,
            processor: ClosePurchaseOrderProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/purchase-orders/{purchaseOrderId}/cancel',
            status: 200,
            processor: CancelPurchaseOrderProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
        ),
    ],
)]
final class PurchaseOrderResource
{
    public const string READ = 'purchase_order:read';
    public const string WRITE = 'purchase_order:write';
    public const array STATUSES = ['draft', 'sent', 'partly_received', 'received', 'closed', 'cancelled'];
    /** Nulls are answered: a draft's absent number and a price withheld read alike; no identifiers read `{}`. */
    public const array NORMALIZATION = ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false, AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true];
    private const array TEXT_OR_NULL = ['type' => ['string', 'null']];
    private const array ID = ['type' => 'string', 'format' => 'uuid'];
    private const array DAY = ['type' => 'string', 'format' => 'date'];
    private const array DAY_OR_NULL = ['type' => ['string', 'null'], 'format' => 'date'];
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The version read; a revision sends back the one it was opened at, and one saved since answers 409. */
    #[Assert\NotNull(groups: [RecordVersion::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\Positive(groups: [RecordVersion::REVISE])]
    #[Groups([self::READ, RecordVersion::REVISE])]
    public ?int $version = null;

    /** The number sending gives the order; null while it is a draft. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $number = null;

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => self::STATUSES])]
    #[Groups([self::READ])]
    public string $status = 'draft';

    /** One of the company's active vendors (GET .../purchase-order-vendors). */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $vendorId = '';

    /** Who the order goes to: the vendor's name as a draft last read it, or the day the order was sent. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $vendorName = '';

    /** One of the company's establishments; left out, the default one. */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $establishmentId = null;

    /**
     * Who the vendor was and where, the day the order was sent; null before.
     *
     * @var array<string, mixed>|null
     */
    #[ApiProperty(writable: false, schema: [
        'type' => ['object', 'null'],
        'required' => ['number', 'name', 'legalName', 'identifiers', 'line1', 'line2', 'postalCode', 'city', 'countryCode', 'email', 'phone'],
        'properties' => [
            'number' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'legalName' => self::TEXT_OR_NULL,
            'identifiers' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'line1' => self::TEXT_OR_NULL,
            'line2' => self::TEXT_OR_NULL,
            'postalCode' => self::TEXT_OR_NULL,
            'city' => self::TEXT_OR_NULL,
            'countryCode' => self::TEXT_OR_NULL,
            'email' => self::TEXT_OR_NULL,
            'phone' => self::TEXT_OR_NULL,
        ],
    ])]
    #[Groups([self::READ])]
    public ?array $vendorSnapshot = null;

    /** The company's day the order was sent; null while it is a draft. */
    #[ApiProperty(writable: false, schema: self::DAY_OR_NULL)]
    #[Groups([self::READ])]
    public ?string $issueDate = null;

    /** The day the goods are expected, YYYY-MM-DD. */
    #[ApiProperty(schema: self::DAY_OR_NULL)]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $expectedOn = null;

    /** Printed on the order, for the vendor. */
    #[Assert\Length(max: PurchaseOrderHeader::TEXT_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $remarksPrinted = null;

    /** Kept inside the company, never printed. */
    #[Assert\Length(max: PurchaseOrderHeader::TEXT_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notesInternal = null;

    /**
     * The lines, in order. A line names the product it orders and how much of it, in the product's unit; it may leave
     * out its description (the product's name) and its price (the product's cost, for whoever may read costs). The
     * received and outstanding quantities and the net amount are answered, never read.
     *
     * @var list<array<string, mixed>>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['productId', 'quantity'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid', 'readOnly' => true, 'description' => 'What a receipt names the line by.'],
                'productId' => self::ID,
                'productReference' => ['type' => 'string', 'readOnly' => true, 'description' => 'The product\'s reference as the line was written.'],
                'productTracking' => ['type' => ['string', 'null'], 'enum' => ['none', 'lot', 'serial', null], 'readOnly' => true, 'description' => 'How the product\'s stock is told apart today, so a receipt knows whether to name a lot.'],
                'description' => ['type' => ['string', 'null'], 'maxLength' => PurchaseOrderLineDetails::DESCRIPTION_MAX],
                'quantity' => ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '12'],
                'unitId' => ['type' => 'string', 'format' => 'uuid', 'readOnly' => true],
                'unitCode' => ['type' => 'string', 'readOnly' => true, 'description' => 'The unit\'s code, C62 for a piece.'],
                'unitName' => ['type' => 'string', 'readOnly' => true, 'description' => 'The unit as the company names it.'],
                'unitPriceNet' => ['type' => ['string', 'null'], 'pattern' => '^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$', 'example' => '12.5000', 'description' => 'Null when unknown, or withheld from whoever may not read costs.'],
                'net' => ['type' => ['string', 'null'], 'readOnly' => true],
                'received' => ['type' => 'string', 'readOnly' => true],
                'outstanding' => ['type' => 'string', 'readOnly' => true],
            ],
        ],
    ])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Collection(fields: [
        'productId' => new Assert\Required([new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        'description' => new Assert\Optional([new Assert\Type(['string', 'null'], groups: [self::WRITE]), new Assert\Length(max: PurchaseOrderLineDetails::DESCRIPTION_MAX, groups: [self::WRITE])], groups: [self::WRITE]),
        'quantity' => new Assert\Required([new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'unitPriceNet' => new Assert\Optional([new Assert\Type(['string', 'null'], groups: [self::WRITE])], groups: [self::WRITE]),
    ], allowExtraFields: true, groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $lines = [];

    /** The lines' net amounts added up; null when a line has no price, or for whoever may not read costs. */
    #[ApiProperty(writable: false, schema: self::TEXT_OR_NULL)]
    #[Groups([self::READ])]
    public ?string $totalNet = null;

    /**
     * The receipts against the order, the oldest first, each with what came of each line, where and in which lot.
     *
     * @var list<array<string, mixed>>
     */
    #[ApiProperty(writable: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'receivedOn', 'supplierReference', 'lines'],
            'properties' => [
                'id' => self::ID,
                'receivedOn' => self::DAY,
                'supplierReference' => self::TEXT_OR_NULL,
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['orderLineId', 'locationId', 'quantity', 'lotCode', 'lotExpiresOn', 'unitCost'],
                        'properties' => [
                            'orderLineId' => self::ID,
                            'locationId' => self::ID,
                            'quantity' => ['type' => 'string'],
                            'lotCode' => self::TEXT_OR_NULL,
                            'lotExpiresOn' => self::DAY_OR_NULL,
                            'unitCost' => ['type' => ['string', 'null'], 'description' => 'Null when none was given, or withheld from whoever may not read costs.'],
                        ],
                    ],
                ],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $receipts = [];

    /**
     * @param array<string, string> $tracking each named product's tracking today, by product id
     */
    public static function of(PurchaseOrder $order, bool $seesCost, int $scale, array $tracking): self
    {
        $header = $order->getHeader();
        $resource = new self();
        $resource->id = $order->getId()->toRfc4122();
        $resource->version = $order->getVersion();
        $resource->number = $order->getNumber();
        $resource->status = $order->getStatus()->value;
        $resource->vendorId = $order->getVendorId()->toRfc4122();
        $resource->vendorName = $order->getVendorName();
        $resource->establishmentId = $order->getEstablishment()->getId()->toRfc4122();
        $snapshot = $order->getVendorSnapshot();
        $resource->vendorSnapshot = null === $snapshot ? null : ['identifiers' => new \ArrayObject($snapshot->identifiers)] + $snapshot->toArray();
        $resource->issueDate = $order->getIssueDate()?->format('Y-m-d');
        $resource->expectedOn = $header->expectedOn?->format('Y-m-d');
        $resource->remarksPrinted = $header->remarksPrinted;
        $resource->notesInternal = $header->notesInternal;

        $totals = $seesCost ? PurchaseOrderTotals::of($order, $scale) : null;
        foreach ($order->getLines() as $index => $line) {
            $resource->lines[] = [
                'id' => $line->getId()->toRfc4122(),
                'productId' => $line->getProductId()->toRfc4122(),
                'productReference' => $line->getProductReference(),
                'productTracking' => $tracking[$line->getProductId()->toRfc4122()] ?? null,
                'description' => $line->getDescription(),
                'quantity' => $line->getQuantity(),
                'unitId' => $line->getUnit()->getId()->toRfc4122(),
                'unitCode' => $line->getUnit()->getCode(),
                'unitName' => $line->getUnit()->getName(),
                'unitPriceNet' => $seesCost ? $line->getUnitPriceNet() : null,
                'net' => $totals?->nets[$index],
                'received' => $line->getReceived(),
                'outstanding' => $line->outstanding(),
            ];
        }
        $resource->totalNet = $totals?->total;
        $resource->receipts = array_map(static fn (GoodsReceipt $receipt): array => [
            'id' => $receipt->getId()->toRfc4122(),
            'receivedOn' => $receipt->getReceivedOn()->format('Y-m-d'),
            'supplierReference' => $receipt->getSupplierReference(),
            'lines' => array_map(static fn (GoodsReceiptLine $line): array => [
                'orderLineId' => $line->getOrderLine()->getId()->toRfc4122(),
                'locationId' => $line->getLocationId()->toRfc4122(),
                'quantity' => $line->getQuantity(),
                'lotCode' => $line->getLotCode(),
                'lotExpiresOn' => $line->getLotExpiresOn()?->format('Y-m-d'),
                'unitCost' => $seesCost ? $line->getUnitCost() : null,
            ], $receipt->getLines()),
        ], $order->getReceipts());

        return $resource;
    }

    public function input(): PurchaseOrderInput
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[] = new PurchaseOrderLineInput(
                Uuid::fromString(self::text($line, 'productId') ?? ''),
                self::text($line, 'quantity') ?? '',
                self::text($line, 'description'),
                self::text($line, 'unitPriceNet'),
            );
        }

        return new PurchaseOrderInput(
            Uuid::fromString($this->vendorId),
            null === $this->establishmentId ? null : Uuid::fromString($this->establishmentId),
            new PurchaseOrderHeader(
                null === $this->expectedOn ? null : new \DateTimeImmutable($this->expectedOn, new \DateTimeZone('UTC')),
                $this->remarksPrinted,
                $this->notesInternal,
            ),
            $lines,
        );
    }

    /** @param array<string, mixed> $line */
    private static function text(array $line, string $key): ?string
    {
        $value = $line[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
}
