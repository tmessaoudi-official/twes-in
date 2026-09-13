<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Unit;

/** Everything a company may change on a unit; the code is fixed once created. */
final readonly class UnitChanges
{
    public function __construct(public string $name, public int $decimals, public bool $isActive, public int $sortOrder)
    {
    }
}
