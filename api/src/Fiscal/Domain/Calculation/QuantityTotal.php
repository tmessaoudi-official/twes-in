<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/** What a document's lines come to in one unit, written with that unit's decimals. */
final readonly class QuantityTotal
{
    public function __construct(
        public string $unitName,
        public int $decimals,
        public string $quantity,
    ) {
    }
}
