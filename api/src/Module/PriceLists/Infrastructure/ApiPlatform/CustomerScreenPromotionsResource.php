<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The promotions the customer screen may show for the products it lists, read with product.read: the final
 * tax-included price, the minimum quantity and the dates of each, from the price lists made for everyone and running
 * today (docs/SPEC.md § 7, 2026-10-03 08:20). A list tied to a customer or a group never appears.
 */
#[ApiResource(
    shortName: 'CustomerScreenPromotions',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/customer-screen/promotions',
            provider: CustomerScreenPromotionsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'ids' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 20],
                    description: 'The products the screen lists. Left out, nothing is answered.',
                ),
            ],
        ),
    ],
)]
final class CustomerScreenPromotionsResource
{
    public const string READ = 'customer_screen_promotions:read';

    /** @var list<array{productId: string, price: string, minQuantity: string, startsOn: string|null, endsOn: string|null}> */
    #[ApiProperty(identifier: false, required: true, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['productId', 'price', 'minQuantity', 'startsOn', 'endsOn'],
            'properties' => [
                'productId' => ['type' => 'string'],
                'price' => ['type' => 'string'],
                'minQuantity' => ['type' => 'string'],
                'startsOn' => ['type' => ['string', 'null'], 'format' => 'date'],
                'endsOn' => ['type' => ['string', 'null'], 'format' => 'date'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $items = [];
}
