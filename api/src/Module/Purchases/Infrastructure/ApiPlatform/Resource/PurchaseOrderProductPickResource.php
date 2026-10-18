<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Purchases\Application\OrderProduct;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider\PurchaseOrderProductPickProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The goods the order form offers while a person types a line, under the orders' own permission, a few at a time. What
 * a product costs today, the price a new line starts from, is told only to whoever may read costs.
 */
#[ApiResource(
    shortName: 'PurchaseOrderProductPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/purchase-order-options/products',
            name: self::PICK,
            provider: PurchaseOrderProductPickProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 100],
                    description: 'Words found in the reference or name, or one of its codes spelled whole, whatever their case and accents. Left out, the first few by reference. Only goods are offered.',
                ),
                'ids' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 20],
                    description: 'Resolves products an order already names, retired ones too. Given, `q` is ignored.',
                ),
            ],
        ),
    ],
)]
final class PurchaseOrderProductPickResource
{
    public const string READ = 'purchase_order_product_pick:read';
    public const string PICK = 'purchase_order_product_pick';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $reference = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    /** The unit the product is counted in, which a line's quantity is counted in. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $unitId = '';

    /** That unit as the company names it, so a line not yet saved says what its quantity counts. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $unitName = '';

    /** How its stock is told apart: none, lot or serial; a receipt of a product kept by lot names the lot. */
    #[ApiProperty(required: true, schema: ['type' => 'string', 'enum' => ['none', 'lot', 'serial']])]
    #[Groups([self::READ])]
    public string $tracking = 'none';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public bool $active = true;

    /** What one unit costs today; null when unknown, or withheld from whoever may not read costs. */
    #[ApiProperty(required: true, schema: ['type' => ['string', 'null']])]
    #[Groups([self::READ])]
    public ?string $costPrice = null;

    public static function of(OrderProduct $product, bool $seesCost): self
    {
        $resource = new self();
        $resource->id = $product->id->toRfc4122();
        $resource->reference = $product->reference;
        $resource->name = $product->name;
        $resource->unitId = $product->unitId->toRfc4122();
        $resource->unitName = $product->unitName;
        $resource->tracking = $product->tracking;
        $resource->active = $product->active;
        $resource->costPrice = $seesCost ? $product->costPrice : null;

        return $resource;
    }
}
