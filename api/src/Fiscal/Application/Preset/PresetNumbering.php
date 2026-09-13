<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/** The default numbering of one document type; numbering series themselves arrive at G3b. */
final readonly class PresetNumbering
{
    public function __construct(public string $format, public string $reset)
    {
    }
}
