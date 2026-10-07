<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use App\Module\Quotes\Domain\QuoteSearch;
use App\Module\Quotes\Domain\QuoteStatus;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;

/** The one reading of a quotes list's query string, for the list and its status counts. */
final class QuoteSearchReader
{
    /**
     * @param array<array-key, mixed>     $parameters the query string
     * @param array<string, 'asc'|'desc'> $order      what the caller read of `order[…]`
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, ?string $text, array $order): QuoteSearch
    {
        $filters = new ListFilters($parameters);
        $issueDate = $filters->dateRange('issueDate');

        return new QuoteSearch(
            $text,
            array_map(QuoteStatus::from(...), $filters->choices('status', array_column(QuoteStatus::cases(), 'value'))),
            $filters->uuids('customerId'),
            $order,
            $issueDate->isOpen() ? null : $issueDate,
        );
    }
}
