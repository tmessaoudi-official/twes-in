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
use App\Module\PriceLists\Application\ResolvedPrice;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The net unit price a sale of a product starts at, read with product.read (docs/SPEC.md § 7, 2026-09-20 price lists):
 * the customer's own list, else the list of the customer's group, else the one for everyone, else the product's shelf
 * price, from the quantity asked and on the day asked (today in the company's time zone when left out). Says which
 * list and which quantity break priced it, none for the shelf price. A quantity that is not a positive decimal with at
 * most three decimals answers 422.
 */
#[ApiResource(
    shortName: 'ProductPrice',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/products/{productId}/price',
            provider: ProductPriceProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'customerId' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], description: 'The customer buying; without one, the lists for everyone.'),
                'quantity' => new QueryParameter(schema: ['type' => 'string', 'pattern' => '^[0-9]{1,11}(\\.[0-9]{1,3})?$'], description: 'How many are bought, 1 when left out.'),
                'on' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'The day of the sale, as YYYY-MM-DD.'),
            ],
        ),
    ],
)]
final class ProductPriceResource
{
    public const string READ = 'product_price:read';

    #[ApiProperty(identifier: false, required: true)]
    #[Groups([self::READ])]
    public string $productId = '';

    /** Net of tax, with four decimals. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $unitPriceNet = '0';

    /** The list that priced it; null for the shelf price. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public ?string $priceListId = null;

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public ?string $priceListName = null;

    /** The quantity break of the row that priced it; null for the shelf price. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public ?string $minQuantity = null;

    public static function of(string $productId, ResolvedPrice $price): self
    {
        $resource = new self();
        $resource->productId = $productId;
        $resource->unitPriceNet = $price->unitPriceNet;
        $resource->priceListId = $price->priceListId?->toRfc4122();
        $resource->priceListName = $price->priceListName;
        $resource->minQuantity = $price->minQuantity;

        return $resource;
    }
}
