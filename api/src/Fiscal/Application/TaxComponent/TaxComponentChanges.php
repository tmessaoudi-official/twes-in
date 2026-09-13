<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\TaxComponent;

/** Everything a company may change on a tax component; the code and the family are fixed once created. */
final readonly class TaxComponentChanges
{
    public function __construct(
        public string $name,
        public ?string $rate,
        public ?string $amount,
        public ?string $threshold,
        public bool $entersVatBase,
        public bool $isDefault,
        public bool $isActive,
        public ?string $exemptionMention,
        public int $sortOrder,
    ) {
    }
}
