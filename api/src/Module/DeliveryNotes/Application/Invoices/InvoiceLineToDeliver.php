<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Invoices;

use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Products\Domain\Model\Product;
use Symfony\Component\Uid\Uuid;

/**
 * One line of an issued invoice whose goods it billed itself: what a note delivering it keeps (its product, unit, price
 * and taxes), and what of it came back to stock through the credit notes issued against it, which is no longer owed.
 */
final readonly class InvoiceLineToDeliver
{
    /**
     * @param list<TaxComponent> $taxes
     * @param string             $returned   the decimal quantity the issued credit notes of the line took back into stock
     * @param bool               $discounted whether a discount on the line or on the whole invoice lowered what it billed
     *                                       below its price, which a note, carrying none, would print
     */
    public function __construct(
        public Uuid $id,
        public int $position,
        public Product $product,
        public string $description,
        public string $quantity,
        public Unit $unit,
        public string $unitPriceNet,
        public array $taxes,
        public string $returned,
        public bool $discounted,
    ) {
    }
}
