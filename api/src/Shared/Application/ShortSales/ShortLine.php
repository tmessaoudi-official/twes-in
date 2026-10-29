<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\ShortSales;

/**
 * A product a sale takes more of than its places hold, named at the first of the document's lines selling it: what all
 * its lines want, what its places hold as recorded (below zero after an earlier such sale), its unit, and the place the
 * shortage would show at, where a count or a receipt puts it right.
 */
final readonly class ShortLine
{
    public function __construct(
        public int $index,
        public string $productId,
        public string $reference,
        public string $name,
        public string $wanted,
        public string $onHand,
        public string $unit,
        public string $locationId,
    ) {
    }

    /** @return array<string, string> what a screen says the line with */
    public function params(): array
    {
        return [
            'product' => $this->reference,
            'name' => $this->name,
            'wanted' => $this->wanted,
            'on_hand' => $this->onHand,
            'unit' => $this->unit,
            'product_id' => $this->productId,
            'location_id' => $this->locationId,
        ];
    }
}
