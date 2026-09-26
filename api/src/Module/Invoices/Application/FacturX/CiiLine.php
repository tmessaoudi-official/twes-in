<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/**
 * An invoice line (BG-25): its id (BT-126), the item's name (BT-153) and the seller's reference (BT-155), the net price
 * of one unit (BT-146), the quantity and its UN/ECE Rec. 20 unit (BT-129, BT-130), the line's own discount as a
 * percentage of an amount (BT-138, BT-137, BT-136), its VAT (BG-30) and its net amount (BT-131).
 */
final readonly class CiiLine
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $sellerItemId,
        public string $netPrice,
        public string $quantity,
        public string $unitCode,
        public ?string $allowancePercent,
        public ?string $allowanceBasis,
        public ?string $allowanceAmount,
        public CiiVat $vat,
        public string $lineTotal,
    ) {
    }
}
