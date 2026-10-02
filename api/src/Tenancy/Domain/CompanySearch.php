<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/**
 * What the platform's companies list asks for: words found in a company's name or in the address of one of its owners,
 * whatever their case and accents, the status and country that narrow it, and the order, always ending on the name so
 * a page never shifts.
 */
final readonly class CompanySearch
{
    public const array SORTS = ['status', 'countryCode', 'createdAt', 'name'];

    /** @param array<string, 'asc'|'desc'> $order one of SORTS per key, in the order it applies */
    public function __construct(
        public ?string $text = null,
        public ?string $status = null,
        public ?string $countryCode = null,
        public array $order = [],
    ) {
    }
}
