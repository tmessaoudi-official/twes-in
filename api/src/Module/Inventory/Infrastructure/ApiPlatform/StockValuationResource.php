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
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What the company's stock is worth (docs/SPEC.md § 7): each product at the weighted average of what came in, and the
 * total. Stock whose cost is not known is counted in `unvaluedQuantity` and adds nothing to the value, so the total is
 * never a guess. Read with stock.read AND product.cost.read, since it shows what the goods cost.
 */
#[ApiResource(
    shortName: 'StockValuation',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/stock-valuation',
            provider: StockValuationProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class StockValuationResource
{
    public const string READ = 'stock_valuation:read';

    /** The total of every line's value, at the currency's decimals. */
    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $total = '0.000';

    /** @var list<array<string, mixed>> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['productId', 'productReference', 'productName', 'unitCode', 'quantity', 'unitCost', 'value', 'unvaluedQuantity'],
            'properties' => [
                'productId' => ['type' => 'string'],
                'productReference' => ['type' => 'string'],
                'productName' => ['type' => 'string'],
                'unitCode' => ['type' => 'string'],
                'quantity' => ['type' => 'string'],
                'unitCost' => ['type' => ['string', 'null'], 'description' => 'The average cost of one unit; null when no stock of it has a cost.'],
                'value' => ['type' => 'string'],
                'unvaluedQuantity' => ['type' => 'string', 'description' => 'The part of the quantity with no known cost, left out of the value.'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $lines = [];
}
