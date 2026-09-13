<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

/** A revision of a numbering series: its format, the number it resumes at, and when it starts again. */
final readonly class NumberingChanges
{
    public function __construct(public string $format, public int $nextNumber, public string $resetPeriod)
    {
    }
}
