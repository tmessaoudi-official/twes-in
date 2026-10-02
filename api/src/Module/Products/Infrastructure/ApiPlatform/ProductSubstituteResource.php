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
use App\Module\Products\Domain\Product;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The other active products of a product's substitution group, by reference (docs/SPEC.md § 7): what replaces it when
 * it cannot be had. Read with `product.read`. Their stock is the inventory's to say.
 */
#[ApiResource(
    shortName: 'ProductSubstitute',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/products/{productId}/substitutes',
            provider: ProductSubstitutesProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            paginationEnabled: false,
        ),
    ],
)]
final class ProductSubstituteResource
{
    public const string READ = 'product_substitute:read';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $reference = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $name = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $isActive = true;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $unitPriceNet = '';

    public static function of(Product $product): self
    {
        $resource = new self();
        $resource->id = $product->getId()->toRfc4122();
        $resource->reference = $product->getReference();
        $resource->name = $product->getDetails()->name;
        $resource->isActive = $product->isActive();
        $resource->unitPriceNet = $product->getDetails()->unitPriceNet;

        return $resource;
    }
}
