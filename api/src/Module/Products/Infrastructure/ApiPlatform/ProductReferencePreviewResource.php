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
 * The reference a new product left without one would be given if it were saved now, in that category: what the
 * product form shows in the field. Nothing is taken; the save takes the next free one, which another save in between
 * moves on. For whoever may add a product.
 */
#[ApiResource(
    shortName: 'ProductReferencePreview',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/product-reference-preview',
            provider: ProductReferencePreviewProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'categoryId' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], description: 'The category the product is filed in, whose own format wins over the company\'s.'),
            ],
        ),
    ],
)]
final class ProductReferencePreviewResource
{
    public const string READ = 'product_reference_preview:read';

    #[ApiProperty(identifier: false, required: true)]
    #[Groups([self::READ])]
    public string $reference = '';
}
