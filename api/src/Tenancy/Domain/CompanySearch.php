<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/**
 * What the platform's companies list asks for: words found in a company's name or in the address of one of its owners,
 * whatever their case and accents, the statuses and countries that narrow it — the values of one OR'd, the two AND'd
 * (docs/SPEC.md § 7, 2026-10-06 00:15) — and the order, always ending on the name so a page never shifts.
 */
final readonly class CompanySearch
{
    public const array SORTS = ['status', 'countryCode', 'createdAt', 'name'];
    public const array STATUSES = [Company::STATUS_PENDING, Company::STATUS_ACTIVE, Company::STATUS_SUSPENDED];

    /**
     * @param list<string>                $statuses     any of STATUSES
     * @param list<string>                $countryCodes any of them, ISO 3166-1 alpha-2
     * @param array<string, 'asc'|'desc'> $order        one of SORTS per key, in the order it applies
     */
    public function __construct(
        public ?string $text = null,
        public array $statuses = [],
        public array $countryCodes = [],
        public array $order = [],
    ) {
    }
}
