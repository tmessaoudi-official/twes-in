<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use App\Module\Expenses\Domain\ExpenseSearch;
use App\Module\Expenses\Domain\ExpenseStatus;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;
use App\Shared\Domain\PaymentMethod;

/**
 * The one reading of an expenses list's query string, for the list, its status counts and its CSV and Excel files, so
 * that what the screen shows, counts and downloads is narrowed by the same words (docs/SPEC.md § 7, 2026-10-06 00:15).
 */
final class ExpenseSearchReader
{
    /**
     * @param array<array-key, mixed>     $parameters the query string
     * @param array<string, 'asc'|'desc'> $order      what the caller read of `order[…]`
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, ?string $text, array $order, bool $withStatus = true): ExpenseSearch
    {
        $filters = new ListFilters($parameters);
        $withheld = $filters->choices('withheld', ['yes', 'no']);
        $date = $filters->dateRange('date');
        $amountGross = $filters->decimalRange('amountGross');

        return new ExpenseSearch(
            $text,
            $withStatus ? array_map(ExpenseStatus::from(...), $filters->choices('status', array_column(ExpenseStatus::cases(), 'value'))) : [],
            $filters->uuids('vendorId'),
            $filters->uuids('categoryId'),
            $order,
            array_map(PaymentMethod::from(...), $filters->choices('paymentMethod', array_column(PaymentMethod::cases(), 'value'))),
            [] === $withheld ? null : 'yes' === $withheld[0],
            $date->isOpen() ? null : $date,
            $amountGross->isOpen() ? null : $amountGross,
        );
    }
}
