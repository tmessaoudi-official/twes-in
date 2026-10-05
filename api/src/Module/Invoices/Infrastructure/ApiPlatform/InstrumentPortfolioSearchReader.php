<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use App\Module\Invoices\Domain\InstrumentKind;
use App\Module\Invoices\Domain\InstrumentPortfolioSearch;
use App\Module\Invoices\Domain\InstrumentStatus;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;

/**
 * The one reading of the portfolio's query string (docs/SPEC.md § 7, 2026-10-06). `open` is not a status an instrument
 * holds but what still promises money, held and deposited together, so it is one more value of the status filter and is
 * answered as the two it stands for.
 */
final class InstrumentPortfolioSearchReader
{
    public const string OPEN = 'open';

    /**
     * @param array<array-key, mixed>     $parameters the query string
     * @param array<string, 'asc'|'desc'> $order      what the caller read of `order[…]`
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, array $order): InstrumentPortfolioSearch
    {
        $filters = new ListFilters($parameters);
        $named = $filters->choices('status', [...array_column(InstrumentStatus::cases(), 'value'), self::OPEN]);
        $statuses = array_map(InstrumentStatus::from(...), array_values(array_unique(array_merge(
            ...array_map(static fn (string $each): array => self::OPEN === $each ? array_column(InstrumentPortfolioSearch::open()->statuses, 'value') : [$each], $named),
        ))));
        $dueOn = $filters->dateRange('dueOn');
        $amount = $filters->decimalRange('amount');

        return new InstrumentPortfolioSearch(
            $statuses,
            $order,
            array_map(InstrumentKind::from(...), $filters->choices('kind', array_column(InstrumentKind::cases(), 'value'))),
            $filters->uuids('customerId'),
            $dueOn->isOpen() ? null : $dueOn,
            $amount->isOpen() ? null : $amount,
        );
    }
}
