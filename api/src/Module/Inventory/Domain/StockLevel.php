<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use Symfony\Component\Uid\Uuid;

/** How much of a product a location holds: the sum of its movements there, signed, with three decimals. */
final readonly class StockLevel
{
    /** A tracked product's stock is one level per lot (docs/SPEC.md § 7, 2026-09-23 02:40); an untracked one's names none. */
    public function __construct(
        public Uuid $productId,
        public Uuid $locationId,
        public string $quantity,
        public ?Uuid $lotId = null,
        public ?string $lotCode = null,
        public ?string $lotExpiresOn = null,
    ) {
    }
}
