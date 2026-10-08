<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use App\Module\Inventory\Application\MapHoldingLine;
use Symfony\Component\Serializer\Attribute\Groups;

/** One place's share of a product in a whereabouts row, its lots together. */
final class StockWhereaboutLine
{
    #[ApiProperty(required: true)]
    #[Groups([StockWhereaboutResource::READ])]
    public string $locationId = '';

    #[ApiProperty(required: true)]
    #[Groups([StockWhereaboutResource::READ])]
    public string $locationCode = '';

    #[ApiProperty(required: true)]
    #[Groups([StockWhereaboutResource::READ])]
    public string $locationName = '';

    #[ApiProperty(required: true)]
    #[Groups([StockWhereaboutResource::READ])]
    public string $quantity = '0.000';

    public static function of(MapHoldingLine $line): self
    {
        $resource = new self();
        $resource->locationId = $line->location->getId()->toRfc4122();
        $resource->locationCode = $line->location->getCode();
        $resource->locationName = $line->location->getName();
        $resource->quantity = $line->quantity;

        return $resource;
    }
}
