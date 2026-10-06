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
 * Whether the customer screen may say a product is in stock, read with product.read (docs/SPEC.md § 7, 2026-10-03
 * 08:20): a yes or a no for goods whose stock is kept at the establishment the screen stands at, never a quantity, and
 * nothing at all until `customer_screen.show_stock` is on for that establishment. Another company's establishment
 * answers 404.
 */
#[ApiResource(
    shortName: 'CustomerScreenAvailability',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/customer-screen/availability',
            provider: CustomerScreenAvailabilityProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'ids' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 20],
                    description: 'The products the screen lists. Left out, nothing is answered.',
                ),
                'establishmentId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'The establishment the screen stands at; left out, the main one.',
                ),
            ],
        ),
    ],
)]
final class CustomerScreenAvailabilityResource
{
    public const string READ = 'customer_screen_availability:read';

    /** @var list<array{productId: string, inStock: bool}> */
    #[ApiProperty(identifier: false, required: true, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['productId', 'inStock'],
            'properties' => ['productId' => ['type' => 'string'], 'inStock' => ['type' => 'boolean']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $items = [];
}
