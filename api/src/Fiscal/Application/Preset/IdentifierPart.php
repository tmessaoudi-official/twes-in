<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/** One part of a registration number: its name, which a refusal says and the screens word, and the pattern it has. */
final readonly class IdentifierPart
{
    /** @param string $pattern a regular expression the part matches whole, without delimiters or anchors */
    public function __construct(
        public string $name,
        public string $pattern,
    ) {
    }
}
