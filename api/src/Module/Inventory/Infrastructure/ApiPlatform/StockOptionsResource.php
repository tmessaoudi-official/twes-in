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
 * What the stock screens offer, read with stock.read alone: the company's establishments. The locations are their own
 * list, and the PRODUCTS are no longer here — they are asked for a few at a time through the picker beside this
 * (docs/SPEC.md § 7, 2026-09-17, ruling 3). Holding them here meant loading every product of the company and walking
 * the settings chain once for each to decide which were stocked, before a screen had drawn anything.
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

    /** @var list<StockEstablishmentOption> */
    #[ApiProperty(identifier: false, schema: [
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
