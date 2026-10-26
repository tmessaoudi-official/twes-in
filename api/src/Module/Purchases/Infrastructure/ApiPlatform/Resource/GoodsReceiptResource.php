<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Module\Purchases\Domain\Model\GoodsReceipt;
use App\Module\Purchases\Domain\ValueObject\ReceivedGoods;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Processor\CorrectReceiptReceiverProcessor;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Processor\ReceiveGoodsProcessor;
use App\Shared\Application\ListLimits;
use App\Shared\Application\Receivers\ReceiverChoice;
use App\Shared\Domain\ValueObject\Receiver;
use App\Shared\Infrastructure\Submissions\Submissions;
use App\Shared\Infrastructure\Validation\ListLimit;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Goods that arrived against a sent order, with purchase.receive: each line names the order line, the place the goods
 * go, how much came, and for a product kept by lot the lot on the goods. The goods are on the shelves when the answer,
 * the order as it now stands, comes back; a line the stock refuses refuses the whole receipt. What one unit cost is
 * read only from whoever may read costs, and left out it is the order's price.
 */
#[ApiResource(
    shortName: 'GoodsReceipt',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/purchase-orders/{purchaseOrderId}/receipts',
            defaults: [Submissions::ROUTE_DEFAULT => true],
            status: 200,
            processor: ReceiveGoodsProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            output: PurchaseOrderResource::class,
            normalizationContext: PurchaseOrderResource::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/purchase-orders/{purchaseOrderId}/receipts/{receiptId}/receiver',
            openapi: new OpenApiOperation(summary: 'Puts right who received a receipt\'s goods, or names nobody.', description: 'Takes `receivedBy` as a receipt does, with purchase.receive, whatever the order\'s status: nothing a receipt moved or printed depends on it. Answers the order. Audited on the order by the field it changed, never the name; the same receiver sent again records nothing. 404 for a receipt of another order; 422 naming `receivedBy` as a receipt refuses it.'),
            status: 200,
            processor: CorrectReceiptReceiverProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            output: PurchaseOrderResource::class,
            normalizationContext: PurchaseOrderResource::NORMALIZATION,
            denormalizationContext: ['groups' => [self::RECEIVE]],
            validationContext: ['groups' => [self::RECEIVE]],
        ),
    ],
)]
final class GoodsReceiptResource
{
    public const string WRITE = 'goods_receipt:write';
    /** Who received, alone: sent to put it right once the receipt is recorded. */
    public const string RECEIVE = 'goods_receipt:receive';
    public const array RECEIVED_BY = [
        'type' => ['object', 'null'],
        'properties' => [
            'memberId' => ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => 'One of the company\'s members, as GET .../purchase-order-options/receivers offers them.'],
            'name' => ['type' => ['string', 'null'], 'maxLength' => Receiver::NAME_MAX, 'description' => 'A name typed, for whoever is no member.'],
        ],
    ];
    private const array RECEIVER_GROUPS = [self::WRITE, self::RECEIVE];

    /** The company's day the goods came, YYYY-MM-DD: not before the order was sent, not after today. */
    #[ApiProperty(schema: ['type' => 'string', 'format' => 'date'])]
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public string $receivedOn = '';

    /** The vendor's own delivery number, printed on what came with the goods. */
    #[Assert\Length(max: GoodsReceipt::REFERENCE_MAX, groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $supplierReference = null;

    /**
     * How the costs given move each product's cost: `average` weighs them with the stock on hand, `last` takes them as
     * they are; left out, the company's « Coût à la réception » setting decides. Read only from whoever may read costs.
     */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'enum' => ['average', 'last', null]])]
    #[Assert\Choice(choices: ['average', 'last'], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $apply = null;

    /**
     * What came, line by line; one order line may be named on several lines, one per place or lot.
     *
     * @var list<array<string, mixed>>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'minItems' => 1,
        'items' => [
            'type' => 'object',
            'required' => ['orderLineId', 'locationId', 'quantity'],
            'properties' => [
                'orderLineId' => ['type' => 'string', 'format' => 'uuid'],
                'locationId' => ['type' => 'string', 'format' => 'uuid'],
                'quantity' => ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '6'],
                'lotCode' => ['type' => ['string', 'null'], 'maxLength' => ReceivedGoods::LOT_CODE_MAX],
                'lotExpiresOn' => ['type' => ['string', 'null'], 'format' => 'date'],
                'unitCost' => ['type' => ['string', 'null'], 'pattern' => '^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$', 'description' => 'What one unit cost, before tax; read only from whoever may read costs.'],
            ],
        ],
    ])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\Count(min: 1, groups: [self::WRITE])]
    #[Assert\All([new Assert\Collection(fields: [
        'orderLineId' => new Assert\Required([new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        'locationId' => new Assert\Required([new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        'quantity' => new Assert\Required([new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'lotCode' => new Assert\Optional([new Assert\Type(['string', 'null'], groups: [self::WRITE])], groups: [self::WRITE]),
        'lotExpiresOn' => new Assert\Optional([new Assert\Type(['string', 'null'], groups: [self::WRITE]), new Assert\Date(groups: [self::WRITE])], groups: [self::WRITE]),
        'unitCost' => new Assert\Optional([new Assert\Type(['string', 'null'], groups: [self::WRITE])], groups: [self::WRITE]),
    ], allowExtraFields: false, groups: [self::WRITE])], groups: [self::WRITE])]
    #[ListLimit(ListLimits::DOCUMENT_LINES, groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public array $lines = [];

    /**
     * Who of the shop received the goods: `{memberId}` one of the company's members, whose name is copied as it reads
     * now; `{name}` any name typed; null or left out, nobody. Refused naming `receivedBy`: `unknown_choice` for no
     * member of the company, `invalid_text`, `receiver_off` where the company's receipts name nobody,
     * `receiver_required` where they must name someone.
     *
     * @var array<string, mixed>|null
     */
    // count: an object of two named fields, not a list; Assert\Collection refuses any other field
    #[ApiProperty(schema: self::RECEIVED_BY)]
    #[Assert\Collection(fields: [
        'memberId' => new Assert\Optional([new Assert\Type(['string', 'null'], groups: self::RECEIVER_GROUPS)], groups: self::RECEIVER_GROUPS),
        'name' => new Assert\Optional([new Assert\Type(['string', 'null'], groups: self::RECEIVER_GROUPS)], groups: self::RECEIVER_GROUPS),
    ], groups: self::RECEIVER_GROUPS)]
    #[Groups([self::WRITE, self::RECEIVE])]
    public ?array $receivedBy = null;

    /** Who the request named as receiver, the member riding in the choice's contact slot; null for nobody. */
    public function receiverChoice(): ?ReceiverChoice
    {
        if (null === $this->receivedBy) {
            return null;
        }
        $memberId = $this->receivedBy['memberId'] ?? null;
        $name = $this->receivedBy['name'] ?? null;

        return new ReceiverChoice(\is_string($memberId) ? $memberId : null, \is_string($name) ? $name : null);
    }
}
