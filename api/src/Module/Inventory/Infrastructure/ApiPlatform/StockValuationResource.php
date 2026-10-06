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
 * total. Stock with no recorded cost is valued at the product's cost price now and counted in `estimatedQuantity`, and
 * `estimated` says the total holds such a part (C-02); stock with no cost at all is counted in `unvaluedQuantity` and
 * adds nothing. Read with stock.read AND product.cost.read, since it shows what the goods cost.
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

    /** Whether any line's value holds an estimate. */
    #[Groups([self::READ])]
    public bool $estimated = false;

    /** @var list<array<string, mixed>> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['productId', 'productReference', 'productName', 'unitCode', 'quantity', 'unitCost', 'value', 'unvaluedQuantity', 'estimatedQuantity'],
            'properties' => [
                'productId' => ['type' => 'string'],
                'productReference' => ['type' => 'string'],
                'productName' => ['type' => 'string'],
                'unitCode' => ['type' => 'string'],
                'quantity' => ['type' => 'string'],
                'unitCost' => ['type' => ['string', 'null'], 'description' => 'The average cost of one unit; null when no stock of it has a cost.'],
                'value' => ['type' => 'string'],
                'unvaluedQuantity' => ['type' => 'string', 'description' => 'The part of the quantity with no known cost, left out of the value.'],
                'estimatedQuantity' => ['type' => 'string', 'description' => 'The part of the quantity with no recorded cost, valued at the product\'s cost price now.'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $lines = [];
}
