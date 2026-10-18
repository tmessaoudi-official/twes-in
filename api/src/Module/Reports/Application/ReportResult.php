<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/**
 * A report as read: its columns, its rows each beside the same row of the period compared (or null where that period had
 * none), and the totals of the columns that add up, for both periods.
 */
final readonly class ReportResult
{
    /**
     * @param list<ReportColumn>                                                     $columns
     * @param list<array{row: ReportRow, previous: ?array<string, string|int|null>}> $rows
     * @param array<string, string|int>                                              $totals
     * @param ?array<string, string|int>                                             $previousTotals
     */
    public function __construct(
        public string $key,
        public ReportPeriod $period,
        public ?ReportPeriod $comparison,
        public array $columns,
        public array $rows,
        public array $totals,
        public ?array $previousTotals,
    ) {
    }
}
