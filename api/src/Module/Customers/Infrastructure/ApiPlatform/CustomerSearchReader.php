<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerSearch;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;
use App\Tenancy\Domain\Company;

/**
 * The one reading of a customers list's query string, for the list and its CSV and Excel files, so that what the
 * screen shows and what it downloads are narrowed by the same words (docs/SPEC.md § 7, 2026-10-06 00:15).
 */
final class CustomerSearchReader
{
    /**
     * @param array<array-key, mixed>     $parameters the query string
     * @param array<string, 'asc'|'desc'> $order      what the caller read of `order[…]`
     * @param list<string>                $regimes    the codes of the tax regimes this company's customers may be under
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, ?string $text, array $order, Company $company, array $regimes): CustomerSearch
    {
        $filters = new ListFilters($parameters);
        // `true` and `false` as a query string says them, `1` and `0` as an older client sent them.
        $active = $filters->choices('isActive', ['true', 'false', '1', '0']);
        $createdOn = $filters->dateRange('createdAt');

        return new CustomerSearch(
            $text,
            array_map(CustomerKind::from(...), $filters->choices('kind', array_column(CustomerKind::cases(), 'value'))),
            $filters->uuids('customerGroupId'),
            [] === $active ? null : \in_array($active[0], ['true', '1'], true),
            $order,
            $filters->choices('taxRegime', $regimes),
            $createdOn->isOpen() ? null : $createdOn,
            $company->getTimezone(),
        );
    }
}
