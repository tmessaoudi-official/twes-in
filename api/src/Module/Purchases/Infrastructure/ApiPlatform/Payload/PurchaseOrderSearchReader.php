<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\Payload;

use App\Module\Purchases\Domain\ValueObject\PurchaseOrderSearch;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderStatus;
use App\Shared\Domain\Exception\InvalidFilter;
use App\Shared\Domain\ValueObject\ListFilters;

/** The one reading of an orders list's query string, for the list and its status counts, so both are narrowed alike. */
final class PurchaseOrderSearchReader
{
    /**
     * @param array<array-key, mixed>     $parameters the query string
     * @param array<string, 'asc'|'desc'> $order      what the caller read of `order[…]`
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, ?string $text, array $order): PurchaseOrderSearch
    {
        $filters = new ListFilters($parameters);
        $issueDate = $filters->dateRange('issueDate');
        $expectedOn = $filters->dateRange('expectedOn');

        return new PurchaseOrderSearch(
            $text,
            array_map(PurchaseOrderStatus::from(...), $filters->choices('status', array_column(PurchaseOrderStatus::cases(), 'value'))),
            $filters->uuids('vendorId'),
            $order,
            $issueDate->isOpen() ? null : $issueDate,
            $expectedOn->isOpen() ? null : $expectedOn,
        );
    }
}
