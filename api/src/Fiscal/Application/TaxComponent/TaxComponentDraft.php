<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\TaxComponent;

final readonly class TaxComponentDraft
{
    public function __construct(
        public string $code,
        public string $name,
        public string $family,
        public ?string $rate,
        public ?string $amount,
        public ?string $threshold,
        public bool $entersVatBase,
        public bool $isDefault,
        public ?string $exemptionMention,
        public int $sortOrder,
    ) {
    }
}
