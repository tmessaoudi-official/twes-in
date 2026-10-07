<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform;

use App\Module\Vendors\Domain\VendorSearch;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;
use App\Tenancy\Domain\Company;

/**
 * The one reading of a vendors list's query string, for the list and its CSV and Excel files, so that what the screen
 * shows and what it downloads are narrowed by the same words (docs/SPEC.md § 7, 2026-10-06 00:15).
 */
final class VendorSearchReader
{
    /**
     * @param array<array-key, mixed>     $parameters the query string
     * @param array<string, 'asc'|'desc'> $order      what the caller read of `order[…]`
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, ?string $text, array $order, Company $company): VendorSearch
    {
        $filters = new ListFilters($parameters);
        // `true` and `false` as a query string says them, `1` and `0` as an older client sent them.
        $active = $filters->choices('isActive', ['true', 'false', '1', '0']);
        $terms = $filters->decimalRange('paymentTermsDays');
        $createdOn = $filters->dateRange('createdAt');

        return new VendorSearch(
            $text,
            [] === $active ? null : \in_array($active[0], ['true', '1'], true),
            $order,
            self::days($terms->min),
            self::days($terms->max),
            $createdOn->isOpen() ? null : $createdOn,
            $company->getTimezone(),
        );
    }

    /** @throws InvalidFilter */
    private static function days(?string $end): ?int
    {
        if (null === $end) {
            return null;
        }
        ctype_digit($end) || throw new InvalidFilter('paymentTermsDays', 'payment terms are whole days.');

        return (int) $end;
    }
}
