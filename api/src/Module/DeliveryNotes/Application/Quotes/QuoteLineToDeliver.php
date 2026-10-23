<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Quotes;

use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Products\Domain\Model\Product;
use Symfony\Component\Uid\Uuid;

/**
 * One line of an accepted quote: what the customer accepted, which a note delivering it keeps (its product, unit, price
 * and taxes) and the invoice of that note bills (its discount, a rate or the line's whole amount).
 */
final readonly class QuoteLineToDeliver
{
    /** @param list<TaxComponent> $taxes */
    public function __construct(
        public Uuid $id,
        public int $position,
        public ?Product $product,
        public string $description,
        public string $quantity,
        public Unit $unit,
        public string $unitPriceNet,
        public array $taxes,
        public ?string $discountRate,
        public ?string $discountAmount,
    ) {
    }
}
