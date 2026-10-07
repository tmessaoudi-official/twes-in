<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductSearch;
use App\Module\Products\Domain\ProductTracking;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;

/**
 * The one reading of a products list's query string, for the list and its CSV and Excel files, so that what the
 * screen shows and what it downloads are narrowed by the same words (docs/SPEC.md § 7, 2026-10-06 00:15).
 */
final class ProductSearchReader
{
    /**
     * @param array<array-key, mixed>     $parameters the query string
     * @param array<string, 'asc'|'desc'> $order      what the caller read of `order[…]`
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, ?string $text, array $order): ProductSearch
    {
        $filters = new ListFilters($parameters);
        // `true` and `false` as a query string says them, `1` and `0` as an older client sent them.
        $active = $filters->choices('isActive', ['true', 'false', '1', '0']);
        $price = $filters->decimalRange('unitPriceNet');

        return new ProductSearch(
            $text,
            array_map(ProductKind::from(...), $filters->choices('kind', array_column(ProductKind::cases(), 'value'))),
            [] === $active ? null : \in_array($active[0], ['true', '1'], true),
            $order,
            array_map(ProductTracking::from(...), $filters->choices('tracking', array_column(ProductTracking::cases(), 'value'))),
            $filters->uuids('categoryId'),
            $price->isOpen() ? null : $price,
        );
    }
}
