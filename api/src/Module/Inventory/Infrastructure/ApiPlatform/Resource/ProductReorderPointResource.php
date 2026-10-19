<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use App\Module\Inventory\Domain\Model\ProductReorderPoint;
use App\Module\Inventory\Infrastructure\ApiPlatform\State\Processor\ClearProductReorderPointProcessor;
use App\Module\Inventory\Infrastructure\ApiPlatform\State\Processor\SetProductReorderPointProcessor;
use App\Module\Inventory\Infrastructure\ApiPlatform\State\Provider\ProductReorderPointCollectionProvider;
use App\Shared\Infrastructure\ApiPlatform\Payload\RecordVersion;
use App\Tenancy\Domain\Model\Establishment;
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
            normalizationContext: ['groups' => [self::READ, self::WRITTEN], 'skip_null_values' => false],
            denormalizationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
            validationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
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
    public const string WRITTEN = 'product_reorder_point:written';

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

    /**
     * A decimal from 0, in the product's unit and never finer than it counts; answered with three decimals. Null where
     * the establishment has none, and sent as null it clears the point, as the list reads one without it.
     */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'example' => '12'])]
    #[Assert\NotBlank(allowNull: true, normalizer: 'trim', groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $quantity = null;

    /**
     * The version of the product the point was read at (the PUT): the points are shown beside the product form, so a
     * change of them moves the product's version, and a point read before another person's change answers 409.
     */
    #[Assert\NotNull(groups: [RecordVersion::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\Positive(groups: [RecordVersion::REVISE])]
    #[Groups([RecordVersion::REVISE])]
    public ?int $version = null;

    /**
     * The product's version the write left (the answer to the PUT): the page's next write sends it at once, rather than
     * the one it read before, which another change a moment later would send again. Null on a list read.
     */
    #[ApiProperty(writable: false)]
    #[Groups([self::WRITTEN])]
    public ?int $productVersion = null;

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
