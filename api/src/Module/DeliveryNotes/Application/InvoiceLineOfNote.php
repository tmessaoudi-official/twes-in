<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Products\Domain\Model\Product;
use Symfony\Component\Uid\Uuid;

/**
 * A delivery note line as an invoice line takes it: what the note says, the quantity invoiced and the line it names,
 * with its share of the discount of the quote line it delivers, when it delivers one.
 * The invoices check it as they check any line they are given, so a note that no longer makes one is refused there.
 */
final readonly class InvoiceLineOfNote
{
    /** @param list<TaxComponent> $taxes */
    public function __construct(
        public ?Product $product,
        public string $description,
        public string $quantity,
        public Unit $unit,
        public string $unitPriceNet,
        public array $taxes,
        public Uuid $deliveryNoteLineId,
        public ?string $lotCode,
        public ?string $discountRate = null,
        public ?string $discountAmount = null,
    ) {
    }
}
