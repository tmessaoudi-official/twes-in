<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use App\Module\Inventory\Domain\StockLevelSearch;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;
use App\Tenancy\Domain\Company;

/**
 * The one reading of a stock list's query string, for the list and its CSV and Excel files, so that what the screen
 * shows and what it downloads are narrowed by the same words (docs/SPEC.md § 7, 2026-10-06 00:15).
 */
final class StockLevelSearchReader
{
    /**
     * @param array<array-key, mixed>     $parameters the query string
     * @param array<string, 'asc'|'desc'> $order      what the caller read of `order[…]`
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, ?string $text, array $order, Company $company): StockLevelSearch
    {
        $filters = new ListFilters($parameters);
        $negative = $filters->choices('negative', ['yes', 'no']);
        $expired = $filters->choices('expired', ['yes', 'no']);
        $useBy = $filters->dateRange('lotExpiresOn');

        return new StockLevelSearch(
            $text,
            $filters->uuids('locationId'),
            $filters->uuids('establishmentId'),
            $order,
            $filters->uuids('productId'),
            $filters->uuids('categoryId'),
            [] === $negative ? null : 'yes' === $negative[0],
            [] === $expired ? null : 'yes' === $expired[0],
            $useBy->isOpen() ? null : $useBy,
            new \DateTimeImmutable('today', new \DateTimeZone($company->getTimezone()))->format('Y-m-d'),
        );
    }
}
