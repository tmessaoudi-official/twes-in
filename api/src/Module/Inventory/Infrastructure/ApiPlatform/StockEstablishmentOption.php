<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/** One of the company's establishments, each the top of a tree of locations. */
final readonly class StockEstablishmentOption
{
    public function __construct(
        #[Groups([StockOptionsResource::READ])]
        public string $id,
        #[Groups([StockOptionsResource::READ])]
        public string $code,
        #[Groups([StockOptionsResource::READ])]
        public string $name,
    ) {
    }
}
