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
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The stock a company holds: one row per product and location anything ever moved in, the sum of those movements, with
 * what a person reads to recognise both. Read with stock.read; ordered by product reference, then location code.
 */
#[ApiResource(
    shortName: 'StockLevel',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-levels',
            provider: StockLevelCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class StockLevelResource
{
    public const string READ = 'stock_level:read';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $productId = '';

    #[Groups([self::READ])]
    public string $productReference = '';

    #[Groups([self::READ])]
    public string $productName = '';

    /** The unit the product's stock is counted in. */
    #[Groups([self::READ])]
    public string $unitCode = '';

    #[Groups([self::READ])]
    public string $locationId = '';

    #[Groups([self::READ])]
    public string $locationCode = '';

    #[Groups([self::READ])]
    public string $locationName = '';

    #[Groups([self::READ])]
    public string $establishmentId = '';

    /** Signed, with three decimals: a negative stock says more left than was ever received or counted. */
    #[Groups([self::READ])]
    public string $quantity = '0.000';
}
