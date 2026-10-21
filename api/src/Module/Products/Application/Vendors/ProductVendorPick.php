<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application\Vendors;

use Symfony\Component\Uid\Uuid;

/** A vendor as the product form's picker shows it. */
final readonly class ProductVendorPick
{
    public function __construct(
        public Uuid $id,
        public string $number,
        public string $name,
        public bool $active,
    ) {
    }
}
