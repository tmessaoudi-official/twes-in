<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/**
 * What a product's code stands for (docs/SPEC.md § 7, 2026-09-22 11:05), in the order a product sheet lists them: the
 * piece it is sold by, a pack that enters several at once, a supplier's own carton, a code the company printed itself.
 */
enum BarcodeRole: string
{
    case Unit = 'unit';
    case Pack = 'pack';
    case Supplier = 'supplier';
    case Internal = 'internal';

    public function rank(): int
    {
        return match ($this) {
            self::Unit => 0,
            self::Pack => 1,
            self::Supplier => 2,
            self::Internal => 3,
        };
    }
}
