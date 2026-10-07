<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use App\Module\Inventory\Domain\StockLossReason;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementKind;
use App\Module\Inventory\Domain\StockMovementSearch;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;
use App\Tenancy\Domain\Company;

/**
 * The one reading of a movements list's query string, for the list and its CSV and Excel files, so that what the
 * screen shows and what it downloads are narrowed by the same words (docs/SPEC.md § 7, 2026-10-06 00:15).
 */
final class StockMovementSearchReader
{
    /** Every source a movement can name, the filter's options. */
    public const array SOURCES = [
        StockMovement::SOURCE_RECEIPT,
        StockMovement::SOURCE_COUNT,
        StockMovement::SOURCE_MOVE,
        StockMovement::SOURCE_LOSS,
        StockMovement::SOURCE_DELIVERY_NOTE,
        StockMovement::SOURCE_INVOICE,
        StockMovement::SOURCE_CREDIT_NOTE,
        StockMovement::SOURCE_COST_CORRECTION,
    ];

    /**
     * @param array<array-key, mixed>     $parameters the query string
     * @param array<string, 'asc'|'desc'> $order      what the caller read of `order[…]`
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, ?string $text, array $order, Company $company): StockMovementSearch
    {
        $filters = new ListFilters($parameters);
        $costToComplete = $filters->choices('costToComplete', ['yes', 'no']);
        $movedOn = $filters->dateRange('movedAt');
        $lot = $parameters['lot'] ?? null;

        return new StockMovementSearch(
            $filters->uuids('productId'),
            $filters->uuids('locationId'),
            $text,
            array_map(StockMovementKind::from(...), $filters->choices('kind', array_column(StockMovementKind::cases(), 'value'))),
            $filters->choices('sourceType', self::SOURCES),
            $order,
            \is_string($lot) && '' !== trim($lot) ? trim($lot) : null,
            array_map(StockLossReason::from(...), $filters->choices('reason', array_column(StockLossReason::cases(), 'value'))),
            [] === $costToComplete ? null : 'yes' === $costToComplete[0],
            $movedOn->isOpen() ? null : $movedOn,
            $company->getTimezone(),
        );
    }
}
