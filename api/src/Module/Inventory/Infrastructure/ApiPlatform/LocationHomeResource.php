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
use App\Module\Inventory\Domain\ProductHomeLocation;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The goods whose home is a place or a place under it, seen from the place: what the stock map's « Ce qu'il y a ici »
 * reads to say a shelf that should hold something holds nothing. Read with stock.read, as the map is: it names what
 * the stock list already names, and sets nothing.
 */
#[ApiResource(
    shortName: 'LocationHome',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-locations/{locationId}/homes',
            provider: LocationHomeCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class LocationHomeResource
{
    public const string READ = 'location_home:read';

    /** A home has an identifier of its own, but this list is read through the place and offers no address for one. */
    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[Groups([self::READ])]
    public string $productId = '';

    #[Groups([self::READ])]
    public string $productReference = '';

    #[Groups([self::READ])]
    public string $productName = '';

    /** The place the home is: the one asked about, or a place under it. */
    #[Groups([self::READ])]
    public string $locationId = '';

    #[Groups([self::READ])]
    public string $locationCode = '';

    /** Whether it is the product's main home in its establishment, the one a receipt proposes. */
    #[Groups([self::READ])]
    public bool $main = false;

    public static function of(ProductHomeLocation $home): self
    {
        $product = $home->getProduct();
        $resource = new self();
        $resource->id = $home->getId()->toRfc4122();
        $resource->productId = $product->getId()->toRfc4122();
        $resource->productReference = $product->getReference();
        $resource->productName = $product->getDetails()->name;
        $resource->locationId = $home->getLocation()->getId()->toRfc4122();
        $resource->locationCode = $home->getLocation()->getCode();
        $resource->main = $home->isMain();

        return $resource;
    }
}
