<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Module\Inventory\Domain\StockLot;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A lot of a product tracked by lot or serial number (docs/SPEC.md § 7, 2026-09-23 02:40). Lots are opened by the
 * movements that name them; what a person does to one is release it, once it has expired, so a delivery may take it.
 * With stock.write.
 */
#[ApiResource(
    shortName: 'StockLot',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/stock-lots/{lotId}/release',
            status: 200,
            processor: ReleaseStockLotProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class StockLotResource
{
    public const string READ = 'stock_lot:read';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[Groups([self::READ])]
    public string $productId = '';

    #[Groups([self::READ])]
    public string $code = '';

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $expiresOn = null;

    /** When someone let the expired lot leave, and who; null until then. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date-time'])]
    #[Groups([self::READ])]
    public ?string $releasedAt = null;

    #[Groups([self::READ])]
    public ?string $releasedBy = null;

    public static function of(StockLot $lot): self
    {
        $resource = new self();
        $resource->id = $lot->getId()->toRfc4122();
        $resource->productId = $lot->getProduct()->getId()->toRfc4122();
        $resource->code = $lot->getCode();
        $resource->expiresOn = $lot->getExpiresOn()?->format('Y-m-d');
        $resource->releasedAt = $lot->getReleasedAt()?->format(\DATE_ATOM);
        $resource->releasedBy = $lot->getReleasedBy()?->toRfc4122();

        return $resource;
    }
}
