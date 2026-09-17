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
            // One page at a time, with its total, which only JSON-LD carries.
            outputFormats: ['jsonld' => ['application/ld+json']],
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'Words found in the product\'s reference or name or in the location\'s code or name, whatever their case and accents; under three characters, the exact reference or code only.'),
                'locationId' => new QueryParameter(schema: self::ID),
                'establishmentId' => new QueryParameter(schema: self::ID),
                'order[reference]' => new QueryParameter(schema: self::DIRECTION),
                'order[product]' => new QueryParameter(schema: self::DIRECTION, description: 'By the product\'s name.'),
                'order[location]' => new QueryParameter(schema: self::DIRECTION, description: 'By the location\'s code.'),
                'order[quantity]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
    ],
)]
final class StockLevelResource
{
    public const string READ = 'stock_level:read';

    private const array ID = ['type' => 'string', 'format' => 'uuid'];
    /** Which way one of the list's sorts reads. */
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];

    /**
     * A row is a product AND a location, so neither alone names it: the pair does, and the pair is what the list keys
     * its rows on. Not the resource's identifier — these rows have no address of their own, and declaring one makes
     * API Platform look for it among the collection's uri variables and answer 404.
     */
    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $id = '';

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
