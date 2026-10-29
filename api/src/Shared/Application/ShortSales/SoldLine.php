<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\ShortSales;

use Symfony\Component\Uid\Uuid;

/** A line of a document about to take goods out of stock, by its place among the document's lines. */
final readonly class SoldLine
{
    public function __construct(
        public int $index,
        public ?Uuid $productId,
        public string $quantity,
        public Uuid $unitId,
        public ?string $lotCode,
    ) {
    }
}
