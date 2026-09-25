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
use App\Module\Inventory\Domain\ProductReorderPoint;
use App\Tenancy\Domain\Establishment;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A product's reorder point in one establishment (docs/SPEC.md § 7, 2026-09-24 11:40): the quantity at or under which
 * it is to be reordered there. Read with product.read, set and cleared with product.write, as a home is. The list
 * answers every establishment of the company, `quantity` null where the product has none, which means no alert there.
 */
#[ApiResource(
    shortName: 'ProductReorderPoint',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/products/{productId}/reorder-points',
            provider: ProductReorderPointCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/products/{productId}/reorder-points/{establishmentId}',
            processor: SetProductReorderPointProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/products/{productId}/reorder-points/{establishmentId}',
            processor: ClearProductReorderPointProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class ProductReorderPointResource
{
    public const string READ = 'product_reorder_point:read';
    public const string WRITE = 'product_reorder_point:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** Which establishment this point is kept for; named in the path, never in the body. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $establishmentId = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $establishmentCode = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $establishmentName = '';

    /** A decimal from 0, in the product's unit and never finer than it counts; answered with three decimals. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'example' => '12'])]
    #[Assert\NotBlank(normalizer: 'trim', groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $quantity = null;

    /** @param ProductReorderPoint|null $point null where the product has none in that establishment */
    public static function of(Establishment $establishment, ?ProductReorderPoint $point): self
    {
        $resource = new self();
        $resource->id = $point?->getId()->toRfc4122();
        $resource->establishmentId = $establishment->getId()->toRfc4122();
        $resource->establishmentCode = $establishment->getCode();
        $resource->establishmentName = $establishment->getName();
        $resource->quantity = $point?->getQuantity();

        return $resource;
    }
}
