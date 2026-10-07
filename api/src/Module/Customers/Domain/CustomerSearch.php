<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain;

use App\Shared\Domain\DateRange;
use Symfony\Component\Uid\Uuid;

/**
 * What a customers list asks for: words found in the number, name, legal name, email, billing address or registration
 * numbers, whatever their case and accents (under three characters, the number only), the filters that combine — the
 * values of one OR'd (several kinds, several groups, several tax regimes), different ones AND'd (docs/SPEC.md § 7,
 * 2026-10-06 00:15) — and the order, always ending on the number so a page never shifts.
 */
final readonly class CustomerSearch
{
    public const array SORTS = ['number', 'name', 'kind', 'customerGroup', 'city', 'isActive'];

    /**
     * @param list<CustomerKind>          $kinds     any of them
     * @param list<Uuid>                  $groups    any of them
     * @param array<string, 'asc'|'desc'> $order     one of SORTS per key, in the order it applies
     * @param list<string>                $regimes   any of these tax regime codes
     * @param DateRange|null              $createdOn the day the customer was created, in the company's calendar
     * @param string                      $timezone  the company's, which that day is read in
     */
    public function __construct(
        public ?string $text = null,
        public array $kinds = [],
        public array $groups = [],
        public ?bool $active = null,
        public array $order = [],
        public array $regimes = [],
        public ?DateRange $createdOn = null,
        public string $timezone = 'UTC',
    ) {
    }
}
