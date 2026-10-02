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
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What is on hand of a product, every location and lot together: how a screen shows the stock of the products it
 * lists without reading each location. A product nothing moved for answers zero. Read with stock.read.
 */
#[ApiResource(
    shortName: 'StockTotal',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-totals',
            provider: StockTotalCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'productId' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 50],
                    description: 'The products to answer for.',
                ),
            ],
        ),
    ],
)]
final class StockTotalResource
{
    public const string READ = 'stock_total:read';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $productId = '';

    /** @var numeric-string */
    #[Groups([self::READ])]
    public string $quantity = '0.000';
}
