<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/**
 * What one part of a number asks of another: `part` matches `pattern` unless `unlessPart` matches `unlessPattern`, as a
 * Tunisian matricule's category is E for any establishment but the seat, 000.
 */
final readonly class IdentifierPartRule
{
    /** @param string $name what the screens word the rule by */
    public function __construct(
        public string $name,
        public string $part,
        public string $pattern,
        public string $unlessPart,
        public string $unlessPattern,
    ) {
    }
}
