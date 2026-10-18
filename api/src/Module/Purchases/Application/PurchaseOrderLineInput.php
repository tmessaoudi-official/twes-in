<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use Symfony\Component\Uid\Uuid;

/**
 * A line as it is written: the product ordered, how much of it in the product's unit, and what one unit should cost,
 * net. A description left out is the product's name; a price left out starts at the product's cost, for whoever may
 * read costs.
 */
final readonly class PurchaseOrderLineInput
{
    public function __construct(
        public Uuid $productId,
        public string $quantity,
        public ?string $description = null,
        public ?string $unitPriceNet = null,
    ) {
    }
}
