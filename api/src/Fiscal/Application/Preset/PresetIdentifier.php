<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/** A registration number a country's documents carry, as a shape to check; checksums are validators of their own. */
final readonly class PresetIdentifier
{
    /**
     * @param string       $pattern     a regular expression the whole value matches, without delimiters
     * @param list<string> $requiredFor who must carry it: company, business_customer
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $pattern,
        public array $requiredFor,
    ) {
    }
}
