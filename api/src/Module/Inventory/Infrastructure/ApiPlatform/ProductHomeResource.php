<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use App\Module\Inventory\Domain\ProductHomeLocation;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Where a product normally lives, one entry per establishment that has one (docs/SPEC.md row 101). It is what a
 * receipt proposes, never a rule: a movement to any other location is refused by nothing here.
 *
 * Read and set with the PRODUCT permissions, not the stock ones. It is an attribute of the product — it sits on the
 * product screen and in the product file beside the rest of them — and it authorizes nothing: it proposes a shelf,
 * and stock may still be put anywhere. Keying it on stock.write would have meant a product file could set a home
 * only for somebody who also arranges the warehouse, which is not who fills that file in.
 *
 * The establishment is never sent: a location already knows where it is, and two sources for one fact drift.
 */
#[ApiResource(
    shortName: 'ProductHome',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/products/{productId}/home-locations',
            provider: ProductHomeCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/products/{productId}/home-locations',
            processor: SetProductHomeProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/products/{productId}/home-locations/{establishmentId}',
            processor: ClearProductHomeProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class ProductHomeResource
{
    public const string READ = 'product_home:read';
    public const string WRITE = 'product_home:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** Which establishment this is the home for; derived from the location, never sent. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $establishmentId = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $establishmentCode = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $establishmentName = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $locationId = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $locationCode = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $locationName = '';

    public static function of(ProductHomeLocation $home): self
    {
        $establishment = $home->getEstablishment();
        $location = $home->getLocation();
        $resource = new self();
        $resource->id = $home->getId()->toRfc4122();
        $resource->establishmentId = $establishment->getId()->toRfc4122();
        $resource->establishmentCode = $establishment->getCode();
        $resource->establishmentName = $establishment->getName();
        $resource->locationId = $location->getId()->toRfc4122();
        $resource->locationCode = $location->getCode();
        $resource->locationName = $location->getName();

        return $resource;
    }
}
