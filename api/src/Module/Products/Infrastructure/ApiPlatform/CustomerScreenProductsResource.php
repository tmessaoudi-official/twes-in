<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The products the customer screen may show for a scan or some words, read with product.read: the name, the final
 * tax-included price, our own reference and barcode of each, and nothing else (docs/SPEC.md § 7, 2026-10-03 08:20).
 */
#[ApiResource(
    shortName: 'CustomerScreenProducts',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/customer-screen/products',
            provider: CustomerScreenProductsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    required: true,
                    description: 'A scanned code, or the words of a name or reference.',
                ),
            ],
        ),
    ],
)]
final class CustomerScreenProductsResource
{
    public const string READ = 'customer_screen_products:read';

    /** @var list<array{id: string, name: string, reference: string, barcode: string|null, finalPrice: string}> */
    #[ApiProperty(identifier: false, required: true, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'name', 'reference', 'barcode', 'finalPrice'],
            'properties' => [
                'id' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'reference' => ['type' => 'string'],
                'barcode' => ['type' => ['string', 'null']],
                'finalPrice' => ['type' => 'string'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $items = [];
}
