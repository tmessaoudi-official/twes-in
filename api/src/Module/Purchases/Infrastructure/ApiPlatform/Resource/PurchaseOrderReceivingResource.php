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
use App\Module\Purchases\Application\ReceivingPlace;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider\PurchaseOrderReceivingProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * Where a receipt of the order may put its goods: the places of the order's establishment, and the place each line goes
 * to unless the person says otherwise, its product's home there, else the establishment's own place; and whether the
 * person receiving says what the cost becomes. Read with the right to receive, which does not need the right to read
 * the stock.
 */
#[ApiResource(
    shortName: 'PurchaseOrderReceiving',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/purchase-orders/{purchaseOrderId}/receiving',
            provider: PurchaseOrderReceivingProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
        ),
    ],
)]
final class PurchaseOrderReceivingResource
{
    public const string READ = 'purchase_order_receiving:read';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $purchaseOrderId = '';

    /** @var list<array{id: string, parentId: string|null, code: string, name: string, isDefault: bool}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'parentId', 'code', 'name', 'isDefault'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'parentId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                'code' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'isDefault' => ['type' => 'boolean'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $places = [];

    /** @var list<array{orderLineId: string, locationId: string}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['orderLineId', 'locationId'],
            'properties' => [
                'orderLineId' => ['type' => 'string', 'format' => 'uuid'],
                'locationId' => ['type' => 'string', 'format' => 'uuid'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $homes = [];

    /**
     * Whether a receipt's `apply` counts: true where the company leaves to whoever receives what the receipt's cost does
     * to the products' cost, false where the company has decided it.
     */
    #[Groups([self::READ])]
    public bool $asksCostBasis = false;

    /**
     * @param list<ReceivingPlace>  $places
     * @param array<string, string> $homes  each line's place, by order line id
     */
    public static function of(string $purchaseOrderId, array $places, array $homes, bool $asksCostBasis): self
    {
        $resource = new self();
        $resource->purchaseOrderId = $purchaseOrderId;
        $resource->asksCostBasis = $asksCostBasis;
        $resource->places = array_map(static fn (ReceivingPlace $place): array => [
            'id' => $place->id->toRfc4122(),
            'parentId' => $place->parentId?->toRfc4122(),
            'code' => $place->code,
            'name' => $place->name,
            'isDefault' => $place->isDefault,
        ], $places);
        foreach ($homes as $orderLineId => $locationId) {
            $resource->homes[] = ['orderLineId' => $orderLineId, 'locationId' => $locationId];
        }

        return $resource;
    }
}
