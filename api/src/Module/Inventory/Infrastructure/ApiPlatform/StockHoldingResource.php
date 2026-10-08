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
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * How many products a drawn place of a floor holds, the places under it included: what the board writes on each shelf,
 * so a person sees where goods are before pressing anything. Only places holding something are listed; read with
 * stock.read, as the map is. Read apart from the drawings because it changes with every movement and they rarely do.
 */
#[ApiResource(
    shortName: 'StockHolding',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-floors/{floorId}/holdings',
            provider: StockHoldingCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class StockHoldingResource
{
    public const string READ = 'stock_holding:read';

    /** The drawn place, as the drawings name it. */
    #[ApiProperty(identifier: true)]
    #[Groups([self::READ])]
    public string $locationId = '';

    /** How many different products it holds some of. */
    #[Groups([self::READ])]
    public int $products = 0;

    public static function of(string $locationId, int $products): self
    {
        $holding = new self();
        $holding->locationId = $locationId;
        $holding->products = $products;

        return $holding;
    }
}
