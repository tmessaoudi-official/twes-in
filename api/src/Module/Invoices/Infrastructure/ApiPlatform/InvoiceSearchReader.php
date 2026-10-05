<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use App\Module\Invoices\Domain\InvoiceSearch;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;
use App\Tenancy\Domain\Company;

/**
 * The one reading of an invoices list's query string, for the list, its status counts and its CSV and Excel files, so
 * that what the screen shows, counts and downloads is narrowed by the same words (docs/SPEC.md § 7, 2026-10-06).
 */
final class InvoiceSearchReader
{
    /** What the status column shows for an invoice past its due day, which is not a status the document holds. */
    public const string OVERDUE = 'overdue';

    /**
     * @param array<array-key, mixed>     $parameters the query string
     * @param array<string, 'asc'|'desc'> $order      what the caller read of `order[…]`
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, ?string $text, array $order, Company $company, bool $withStatus = true): InvoiceSearch
    {
        $filters = new ListFilters($parameters);
        $statuses = $withStatus ? $filters->choices('status', [...array_column(InvoiceStatus::cases(), 'value'), self::OVERDUE]) : [];
        $overdue = \in_array(self::OVERDUE, $statuses, true);

        return new InvoiceSearch(
            $text,
            array_map(InvoiceStatus::from(...), array_values(array_diff($statuses, [self::OVERDUE]))),
            array_map(InvoiceType::from(...), $filters->choices('documentType', array_column(InvoiceType::cases(), 'value'))),
            $filters->uuids('customerId'),
            $order,
            $overdue ? new \DateTimeImmutable('today', new \DateTimeZone($company->getTimezone())) : null,
            self::open($filters->dateRange('issueDate')),
            self::open($filters->dateRange('dueDate')),
            self::open($filters->decimalRange('totalGross')),
            self::open($filters->decimalRange('amountDue')),
        );
    }

    /**
     * @template T of \App\Shared\Domain\DateRange|\App\Shared\Domain\DecimalRange
     *
     * @param T $range
     *
     * @return T|null
     */
    private static function open(object $range): ?object
    {
        return $range->isOpen() ? null : $range;
    }
}
