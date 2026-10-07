<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What a document's lines say of stock while they are typed (docs/SPEC.md § 7, the live line figures): the quantity on
 * hand at the document's establishment, read with stock.read, for the goods whose stock the company keeps, with the unit
 * it is counted in, since a line in another unit moves none. Another company's establishment answers 404.
 */
#[ApiResource(
    shortName: 'StockOnHand',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/stock-options/on-hand',
            provider: StockOnHandProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'ids' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => self::MOST],
                    description: 'The products the lines name. Left out, nothing is answered.',
                ),
                'establishmentId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'The establishment the document is made at; left out, the main one.',
                ),
            ],
        ),
    ],
)]
final class StockOnHandResource
{
    public const string READ = 'stock_on_hand:read';
    /** As many products as a long document names, each asked once. */
    public const int MOST = 100;

    /** @var list<array{productId: string, unitId: string, onHand: string}> */
    #[ApiProperty(identifier: false, required: true, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['productId', 'unitId', 'onHand'],
            'properties' => [
                'productId' => ['type' => 'string', 'format' => 'uuid'],
                'unitId' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The unit the stock is counted in.'],
                'onHand' => ['type' => 'string', 'description' => 'A decimal, below zero when more left than came in.'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $items = [];
}
