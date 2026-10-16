<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Products\Application\ProductLabels;
use App\Module\Products\Infrastructure\ApiPlatform\State\Provider\ProductLabelCollectionProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * What a sheet of labels prints for the products selected, read with product.read: each product once, in the order
 * asked, with every code it holds and what a customer pays for what the code enters, taxes included, as a scan of the
 * code says it. A product the company does not hold is not answered. No product, or more than a list page, 422.
 */
#[ApiResource(
    shortName: 'ProductLabel',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/product-labels',
            provider: ProductLabelCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            paginationEnabled: false,
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'productId' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'minItems' => 1, 'maxItems' => ProductLabels::MOST],
                    required: true,
                    description: 'The products whose labels are printed, in the order they are printed.',
                ),
            ],
        ),
    ],
)]
final class ProductLabelResource
{
    public const string READ = 'product_label:read';

    private const array CODES = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['code', 'role', 'quantity', 'priceGross'],
            'properties' => [
                'code' => ['type' => 'string'],
                'role' => ['type' => 'string', 'enum' => ['unit', 'pack', 'supplier', 'internal']],
                'quantity' => ['type' => 'integer'],
                'priceGross' => ['type' => 'string'],
            ],
        ],
    ];

    #[ApiProperty(identifier: false, required: true, schema: ['type' => 'string', 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public string $productId = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $reference = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    /** @var list<array{code: string, role: string, quantity: int, priceGross: string}> by role, then by code */
    #[ApiProperty(required: true, schema: self::CODES)]
    #[Groups([self::READ])]
    public array $codes = [];
}
