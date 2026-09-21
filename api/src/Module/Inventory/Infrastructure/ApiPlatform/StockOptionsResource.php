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

    /**
     * The plan palette's ready-made shapes, at this company's own sizes. They travel with the context the plan
     * screen already asks for, so the web never carries a measurement of its own: the approved canvas is explicit
     * that a rack's size is a company's setting and not a constant of the code.
     *
     * @var list<StockPlanShapeOption>
     */
    #[ApiProperty(identifier: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['shape', 'width', 'depth'],
            'properties' => ['shape' => ['type' => 'string'], 'width' => ['type' => 'string'], 'depth' => ['type' => 'string']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $planShapes = [];

    /**
     * The structure layer's four tools, at this company's own measurements. They carry a height where the palette's
     * shapes do not: a wall's height is the same for the whole building until the company says otherwise, while a
     * rack's is a fact about that rack somebody went and measured.
     *
     * @var list<StockStructureShapeOption>
     */
    #[ApiProperty(identifier: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['kind', 'width', 'depth', 'height'],
            'properties' => [
                'kind' => ['type' => 'string'],
                'width' => ['type' => 'string'],
                'depth' => ['type' => 'string'],
                'height' => ['type' => 'string'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $structureShapes = [];
}
