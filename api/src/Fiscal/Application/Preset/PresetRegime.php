<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

use App\Fiscal\Domain\TaxFamily;

/** A tax regime a customer (or a company) is under: which tax families it removes, and what the invoice must say. */
final readonly class PresetRegime
{
    /** @param list<TaxFamily> $excludedFamilies */
    public function __construct(
        public string $code,
        public string $labelKey,
        public array $excludedFamilies,
        public ?string $mentionKey,
        public int $sortOrder,
    ) {
    }
}
