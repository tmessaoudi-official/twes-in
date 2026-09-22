<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use Symfony\Component\Uid\Uuid;

/** One code as it is written to a product: its role and code as typed, how many one scan enters, its supplier's id. */
final readonly class BarcodeInput
{
    public function __construct(
        public string $role,
        public string $code,
        public int $quantity,
        public ?Uuid $supplierId = null,
    ) {
    }
}
