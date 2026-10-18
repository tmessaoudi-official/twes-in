<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use Symfony\Component\Uid\Uuid;

/**
 * One line of a receipt as the stock takes it: the receipt line it comes from, which the movement carries as its
 * source, the product, the place, the quantity at three decimals, the lot on the goods, and what one unit cost when it is
 * known; `$costToComplete` when the person receiving may not read costs and nobody gave one.
 */
final readonly class IntakeLine
{
    public function __construct(
        public Uuid $sourceId,
        public Uuid $productId,
        public Uuid $locationId,
        public string $quantity,
        public ?string $lotCode,
        public ?\DateTimeImmutable $lotExpiresOn,
        public ?string $unitCost,
        public bool $costToComplete,
    ) {
    }
}
