<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Inventory\Application\Count\ReadCountContext;
use App\Module\Inventory\Infrastructure\ApiPlatform\State\Provider\StockCountContextProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What a count would change, read with stock.read while a count is typed or scanned: for each product whose stock is
 * kept, every place and lot something moved in with what is there now, and the places the product lives at. The screen
 * says from it what is expected here, what is elsewhere, that a place is not the product's own, or that a lot is new,
 * before anything is recorded. Another company's product is left out; another company answers 404.
 */
#[ApiResource(
    shortName: 'StockCountContext',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/stock-counts/context',
            provider: StockCountContextProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'productIds' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => ReadCountContext::MOST],
                    description: 'The products counted. Left out, nothing is answered.',
                ),
            ],
        ),
    ],
)]
final class StockCountContextResource
{
    public const string READ = 'stock_count_context:read';

    /** @var list<array{productId: string, places: list<array{locationId: string, lotCode: ?string, quantity: string}>, homeLocationIds: list<string>}> */
    #[ApiProperty(identifier: false, required: true, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['productId', 'places', 'homeLocationIds'],
            'properties' => [
                'productId' => ['type' => 'string', 'format' => 'uuid'],
                'places' => [
                    'type' => 'array',
                    'description' => 'Every place and lot something moved in, by place then lot, an emptied one included.',
                    'items' => [
                        'type' => 'object',
                        'required' => ['locationId', 'lotCode', 'quantity'],
                        'properties' => [
                            'locationId' => ['type' => 'string', 'format' => 'uuid'],
                            'lotCode' => ['type' => ['string', 'null'], 'description' => 'Null for a product kept without lots.'],
                            'quantity' => ['type' => 'string', 'description' => 'A decimal, below zero when more left than came in.'],
                        ],
                    ],
                ],
                'homeLocationIds' => [
                    'type' => 'array',
                    'description' => 'The places the product lives at, in each establishment\'s order; a place under one is at home too.',
                    'items' => ['type' => 'string', 'format' => 'uuid'],
                ],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $items = [];
}
