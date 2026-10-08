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
use App\Module\Inventory\Application\MapHolding;
use App\Module\Inventory\Application\MapHoldingLine;
use App\Module\Products\Domain\Product;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Where a product is, as the stock map lights it: one row per drawn place holding some, the floor it is drawn on and
 * what each place at or under it holds, then one row with no place for what lies where nothing is drawn. Read with
 * stock.read, as the map is.
 */
#[ApiResource(
    shortName: 'StockWhereabout',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-whereabouts',
            provider: StockWhereaboutCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'productId' => new QueryParameter(
                    schema: ['type' => 'string', 'format' => 'uuid'],
                    description: 'The product to find.',
                    required: true,
                ),
            ],
        ),
    ],
)]
final class StockWhereaboutResource
{
    public const string READ = 'stock_whereabout:read';

    // No identifier: a row is an answer about a product, reached only through the uriTemplate above.
    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $key = '';

    /** The floor the place is drawn on; null on the row of what lies where nothing is drawn. */
    #[Groups([self::READ])]
    public ?string $floorId = null;

    #[Groups([self::READ])]
    public ?string $locationId = null;

    #[Groups([self::READ])]
    public ?string $locationCode = null;

    #[Groups([self::READ])]
    public ?string $locationName = null;

    /** What was found, repeated on each row so that one row says all of it. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $productReference = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $productName = '';

    /** The unit the product's stock is counted in, as the screens name it, and how many decimals it counts. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $unitName = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public int $unitDecimals = 3;

    /** All of it at this place and under it, at the stock's three decimals. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $quantity = '0.000';

    /**
     * Each place holding some, by code: the drawn place itself and its bins, or the undrawn places one by one.
     *
     * @var list<StockWhereaboutLine>
     */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public array $lines = [];

    public static function of(MapHolding $holding, Product $product): self
    {
        $resource = new self();
        $place = $holding->place;
        $resource->key = $place?->getId()->toRfc4122() ?? 'undrawn';
        $resource->floorId = $place?->getSpot()?->getArea()->getId()->toRfc4122();
        $resource->locationId = $place?->getId()->toRfc4122();
        $resource->locationCode = $place?->getCode();
        $resource->locationName = $place?->getName();
        $resource->productReference = $product->getReference();
        $resource->productName = $product->getDetails()->name;
        $resource->unitName = $product->getUnit()->getName();
        $resource->unitDecimals = $product->getUnit()->getDecimals();
        $resource->quantity = $holding->quantity;
        $resource->lines = array_map(static fn (MapHoldingLine $line): StockWhereaboutLine => StockWhereaboutLine::of($line), $holding->lines);

        return $resource;
    }
}
