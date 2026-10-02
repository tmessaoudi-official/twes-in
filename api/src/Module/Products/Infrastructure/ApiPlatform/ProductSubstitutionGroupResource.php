<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The substitution groups the company's products carry, by name, each with how many products hold it (docs/SPEC.md
 * § 7): what a product's form offers to join. A group exists while a product carries it. Read with `product.read`.
 */
#[ApiResource(
    shortName: 'ProductSubstitutionGroup',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/product-substitution-groups',
            provider: ProductSubstitutionGroupsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            paginationEnabled: false,
        ),
    ],
)]
final class ProductSubstitutionGroupResource
{
    public const string READ = 'product_substitution_group:read';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $name = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $products = 0;
}
