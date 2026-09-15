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
 * What the stock screens offer, read with stock.read alone: the active products whose stock is kept, with the unit it
 * is counted in, and the company's establishments. The locations are their own list.
 */
#[ApiResource(
    shortName: 'StockOptions',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/stock-options',
            provider: StockOptionsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class StockOptionsResource
{
    public const string READ = 'stock_options:read';

    /** @var list<StockProductOption> */
    #[ApiProperty(identifier: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'reference', 'name', 'unitCode', 'unitDecimals'],
            'properties' => ['id' => ['type' => 'string'], 'reference' => ['type' => 'string'], 'name' => ['type' => 'string'], 'unitCode' => ['type' => 'string'], 'unitDecimals' => ['type' => 'integer']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $products = [];

    /** @var list<StockEstablishmentOption> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'code', 'name'],
            'properties' => ['id' => ['type' => 'string'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $establishments = [];
}
