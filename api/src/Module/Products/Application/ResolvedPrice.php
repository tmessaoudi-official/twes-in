<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use Symfony\Component\Uid\Uuid;

/** The net unit price a sale of a product is made at, and where it came from: the shelf price, or a list's row. */
final readonly class ResolvedPrice
{
    /**
     * @param string              $unitPriceNet net of tax, as the list row or the product keeps it
     * @param numeric-string|null $minQuantity  the quantity break of the row that priced it, none for the shelf
     */
    public function __construct(
        public string $unitPriceNet,
        public ?Uuid $priceListId,
        public ?string $priceListName,
        public ?string $minQuantity,
    ) {
    }
}
