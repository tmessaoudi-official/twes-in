<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Unit;

final readonly class UnitDraft
{
    public function __construct(public string $code, public string $name, public int $decimals, public int $sortOrder)
    {
    }
}
