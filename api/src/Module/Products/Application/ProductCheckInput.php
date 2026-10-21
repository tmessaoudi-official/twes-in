<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Shared\Domain\ValueObject\CheckMark;

/** « À vérifier » as written on a product: the mark a person sets with its note, and a price of nothing meant. */
final readonly class ProductCheckInput
{
    public function __construct(public CheckMark $mark, public bool $zeroPriceIntended = false)
    {
    }
}
