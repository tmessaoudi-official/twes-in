<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Domain;

use App\Shared\Domain\DateRange;

/**
 * What a vendors list asks for (docs/SPEC.md § 7, lists at scale, and 2026-10-06 00:15): words found in the number,
 * name, legal name, email, address or registration numbers, whatever their case and accents (under three characters,
 * the number only), whether they are active, the payment terms as an interval of whole days (a vendor with none is
 * left out by either end), the day it was created in the company's calendar, all AND'd, and the order, always ending
 * on the number so a page never shifts.
 */
final readonly class VendorSearch
{
    public const array SORTS = ['number', 'name', 'city', 'paymentTermsDays', 'isActive'];

    /**
     * @param array<string, 'asc'|'desc'> $order        one of SORTS per key, in the order it applies
     * @param int|null                    $termsAtLeast days, inclusive
     * @param int|null                    $termsAtMost  days, inclusive
     * @param string                      $timezone     the company's, which the creation day is read in
     */
    public function __construct(
        public ?string $text = null,
        public ?bool $active = null,
        public array $order = [],
        public ?int $termsAtLeast = null,
        public ?int $termsAtMost = null,
        public ?DateRange $createdOn = null,
        public string $timezone = 'UTC',
    ) {
    }
}
