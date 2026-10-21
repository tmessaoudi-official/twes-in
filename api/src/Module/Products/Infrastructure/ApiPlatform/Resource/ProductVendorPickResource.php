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
use App\Module\Products\Application\Vendors\ProductVendorPick;
use App\Module\Products\Infrastructure\ApiPlatform\State\Provider\ProductVendorPickProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The vendors the product form offers while a person names who a product is bought from, to whoever may change
 * products, a few at a time: no total and no page. Without the vendors module there is no vendor book, and it answers
 * 404.
 */
#[ApiResource(
    shortName: 'ProductVendorPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/product-options/vendors',
            name: self::PICK,
            provider: ProductVendorPickProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 100],
                    description: 'Words found in the vendor\'s number or name, whatever their case and accents. Left out, the first few active vendors.',
                ),
                'ids' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 20],
                    description: 'Resolves vendors a product already names, retired ones too. Given, `q` is ignored.',
                ),
            ],
        ),
    ],
)]
final class ProductVendorPickResource
{
    public const string READ = 'product_vendor_pick:read';
    public const string PICK = 'product_vendor_pick';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $number = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    /** Whether a product may still be said to be bought from it. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public bool $active = true;

    public static function of(ProductVendorPick $vendor): self
    {
        $resource = new self();
        $resource->id = $vendor->id->toRfc4122();
        $resource->number = $vendor->number;
        $resource->name = $vendor->name;
        $resource->active = $vendor->active;

        return $resource;
    }
}
