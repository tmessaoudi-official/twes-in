<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

/** A cost somebody typed on a receipt, and when. */
final readonly class TypedCost
{
    /** @param numeric-string $cost four decimals */
    public function __construct(public string $cost, public \DateTimeImmutable $at)
    {
    }
}
