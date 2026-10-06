<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/** A price counted on one line of the quantity asked: before tax, its taxes, and what a customer pays. */
final readonly class PricePreview
{
    public function __construct(
        public string $quantity,
        public string $net,
        public string $tax,
        public string $total,
    ) {
    }
}
