<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/**
 * Reads one report over a period and, when asked, the period it is compared with, and lays the two side by side
 * (docs/SPEC.md § 7, 2026-09-20 03:40). A row of the compared period that the period asked has not is kept, with nothing
 * in its figures: a customer who bought last month and not this one is the row that matters most. The totals add up the
 * figures the rows hold, which the report read from the documents; nothing is derived again.
 */
final readonly class RunReport
{
    public function run(DeclaresReport $report, ReportContext $context, ReportPeriod $period, ReportComparison $comparison): ReportResult
    {
        if (null !== $context->establishmentId && !$report->byEstablishment()) {
            throw new InvalidReportRequest(\sprintf('The report %s cannot be narrowed to an establishment.', $report->key()));
        }
        if (!$report->periodic() && ReportComparison::None !== $comparison) {
            throw new InvalidReportRequest(\sprintf('The report %s reads today, and today has nothing to be compared with.', $report->key()));
        }
        $columns = $report->columns($context);
        $current = $report->rows($context, $period);
        $compared = $comparison->of($period);
        $previous = null === $compared ? null : $report->rows($context, $compared);

        $rows = [];
        $earlier = [];
        foreach ($previous ?? [] as $row) {
            $earlier[$row->key] = $row;
        }
        $seen = [];
        foreach ($current as $row) {
            if (isset($seen[$row->key])) {
                throw new \LogicException(\sprintf('The report %s gives the row %s twice.', $report->key(), $row->key));
            }
            $seen[$row->key] = true;
            $rows[] = ['row' => $row, 'previous' => null === $previous ? null : ($earlier[$row->key] ?? null)?->cells];
        }
        foreach ($previous ?? [] as $row) {
            if (!isset($seen[$row->key])) {
                $rows[] = ['row' => new ReportRow($row->key, self::labelsOnly($columns, $row->cells), $row->drill), 'previous' => $row->cells];
            }
        }

        return new ReportResult(
            $report->key(),
            $period,
            $compared,
            $columns,
            $rows,
            self::totals($columns, $current),
            null === $previous ? null : self::totals($columns, $previous),
        );
    }

    /**
     * A row known only from the compared period keeps what names it and nothing it counted.
     *
     * @param list<ReportColumn>             $columns
     * @param array<string, string|int|null> $cells
     *
     * @return array<string, string|int|null>
     */
    private static function labelsOnly(array $columns, array $cells): array
    {
        $kept = [];
        foreach ($columns as $column) {
            $kept[$column->key] = $column->kind->counts() ? null : ($cells[$column->key] ?? null);
        }

        return $kept;
    }

    /**
     * The sum of every column that adds up, as exact decimals at the widest scale its figures were written in; a count
     * stays an integer.
     *
     * @param list<ReportColumn> $columns
     * @param list<ReportRow>    $rows
     *
     * @return array<string, string|int>
     */
    private static function totals(array $columns, array $rows): array
    {
        $totals = [];
        foreach ($columns as $column) {
            if (!$column->kind->adds()) {
                continue;
            }
            if (ReportColumnKind::Count === $column->kind) {
                $totals[$column->key] = array_sum(array_map(static fn (ReportRow $row): int => \is_int($row->cells[$column->key] ?? null) ? $row->cells[$column->key] : 0, $rows));
                continue;
            }
            $figures = array_values(array_filter(array_map(static fn (ReportRow $row): mixed => $row->cells[$column->key] ?? null, $rows), \is_string(...)));
            $scale = max([0, ...array_map(static fn (string $figure): int => false === ($point = strpos($figure, '.')) ? 0 : \strlen($figure) - $point - 1, $figures)]);
            $sum = '0';
            foreach ($figures as $figure) {
                if (!is_numeric($figure)) {
                    throw new \LogicException(\sprintf('A report figure is a decimal written out, not %s.', $figure));
                }
                $sum = bcadd($sum, $figure, $scale);
            }
            $totals[$column->key] = $sum;
        }

        return $totals;
    }
}
