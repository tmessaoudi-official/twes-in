<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Products\Domain\Product;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * The products a stock screen offers while a person types (docs/SPEC.md § 7, 2026-09-17, ruling 3). The same shape
 * `StockOptionsResource::$products` carried, asked for a few at a time: that payload held every product of the
 * company AND evaluated the settings chain once for each of them to decide which were stocked.
 *
 * Only products whose stock is KEPT are offered, which is the one thing that makes this picker different from the
 * document forms' — and the reason it cannot be one of theirs.
 */
#[ApiResource(
    shortName: 'StockProductPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-options/products',
            name: self::PICK,
            provider: StockProductPickProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 100],
                    description: 'Words found in the reference or name, or one of its codes spelled whole (which is then offered first), whatever their case and accents; under three characters, the exact reference only. Left out, the first few by reference.',
                ),
                'ids' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 20],
                    description: 'Resolves records a movement already names, rather than searching: what a screen needs to show what it holds. Unlike a search, this answers a product no stock is kept of any more, because the movements that were made of it are still there. Given, `q` is ignored.',
                ),
            ],
        ),
    ],
)]
final class StockProductPickResource
{
    public const string READ = 'stock_product_pick:read';
    public const string PICK = 'stock_product_pick';

    // No identifier: reached only through the uriTemplate above.
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $reference = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    /** The unit the product's stock is counted in, and how many decimals it counts. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $unitCode = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public int $unitDecimals = 3;

    /**
     * Where this product normally lives, so a receipt proposes it rather than asking the same question every time
     * (docs/SPEC.md row 101). Null where the product has no home, and also where it has one in each of several
     * establishments: this picker knows which product was chosen, not where the goods are arriving.
     */
    #[ApiProperty(schema: ['type' => 'string', 'format' => 'uuid', 'nullable' => true])]
    #[Groups([self::READ])]
    public ?string $homeLocationId = null;

    public static function of(Product $product, ?Uuid $homeLocationId = null): self
    {
        $resource = new self();
        $resource->id = $product->getId()->toRfc4122();
        $resource->reference = $product->getReference();
        $resource->name = $product->getDetails()->name;
        $resource->unitCode = $product->getUnit()->getCode();
        $resource->unitDecimals = $product->getUnit()->getDecimals();
        $resource->homeLocationId = $homeLocationId?->toRfc4122();

        return $resource;
    }
}
