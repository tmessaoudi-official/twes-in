<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Domain;

/**
 * What a vendors list asks for (docs/SPEC.md § 7, lists at scale): words found in the number, name, legal name,
 * email, address or registration numbers, whatever their case and accents (under three characters, the number only),
 * whether they are active, and the order, always ending on the number so a page never shifts.
 */
final readonly class VendorSearch
{
    public const array SORTS = ['number', 'name', 'city', 'paymentTermsDays', 'isActive'];

    /** @param array<string, 'asc'|'desc'> $order one of SORTS per key, in the order it applies */
    public function __construct(
        public ?string $text = null,
        public ?bool $active = null,
        public array $order = [],
    ) {
    }
}
