<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Reports;

use App\Module\Expenses\Domain\ValueObject\ExpenseStatus;
use App\Module\Expenses\Infrastructure\ApiPlatform\Security\ExpensePermission;
use App\Module\Expenses\Infrastructure\Module\ExpensesModule;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportColumn;
use App\Module\Reports\Application\ReportColumnKind;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportDrill;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\ReportRow;
use Doctrine\DBAL\Connection;

/**
 * What the company spent over the period, by category: every expense recorded in the books, paid or not, on the day the
 * bill bears, with its net, its taxes and what it came to. Recording refuses an expense without its category, and a
 * category in use is never deleted, so every expense counted has one. An expense belongs to the company, not to an
 * establishment, so the report is never narrowed to one. An expense is kept in the company's currency, which a company
 * keeps for good, so no sum mixes two.
 */
final readonly class ExpensesReport implements DeclaresReport
{
    private const string RECORDED = 'e.company_id = :company AND e.status IN (:recorded, :paid) AND e.expense_date BETWEEN :from AND :to';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'expenses-by-category';
    }

    public function module(): string
    {
        return ExpensesModule::KEY;
    }

    public function permission(): string
    {
        return ExpensePermission::READ;
    }

    public function byEstablishment(): bool
    {
        return false;
    }

    public function periodic(): bool
    {
        return true;
    }

    public function columns(ReportContext $context): array
    {
        return [
            new ReportColumn('category', ReportColumnKind::Label),
            new ReportColumn('net', ReportColumnKind::Amount),
            new ReportColumn('taxes', ReportColumnKind::Amount),
            new ReportColumn('gross', ReportColumnKind::Amount),
            new ReportColumn('expenses', ReportColumnKind::Count),
        ];
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        // A sub-category is named under its parent, as the category picker shows it.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.category_id, CASE WHEN p.id IS NULL THEN c.name ELSE p.name || \' › \' || c.name END AS name,
                    SUM(e.amount_net) AS net, SUM(e.tax_amount + e.fixed_amount) AS taxes, SUM(e.amount_gross) AS gross, COUNT(*) AS expenses
               FROM expense e
               JOIN expense_category c ON c.id = e.category_id
               LEFT JOIN expense_category p ON p.id = c.parent_id
              WHERE '.self::RECORDED.'
              GROUP BY e.category_id, c.name, p.id, p.name
              ORDER BY SUM(e.amount_gross) DESC, 2, e.category_id',
            $this->params($context, $period),
        );
        $from = $period->from->format('Y-m-d');
        $to = $period->to->format('Y-m-d');

        return array_map(static function (array $row) use ($from, $to): ReportRow {
            $category = self::text($row['category_id']);

            return new ReportRow($category, [
                'category' => self::text($row['name']),
                'net' => self::text($row['net']),
                'taxes' => self::text($row['taxes']),
                'gross' => self::text($row['gross']),
                'expenses' => (int) self::text($row['expenses']),
            ], new ReportDrill('expenses', [
                'category' => $category,
                'status' => ExpenseStatus::Recorded->value.','.ExpenseStatus::Paid->value,
                'date.from' => $from,
                'date.to' => $to,
            ]));
        }, $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        // Every row opens the expenses list instead.
        return [];
    }

    /** @return array<string, string> */
    private function params(ReportContext $context, ReportPeriod $period): array
    {
        return [
            'company' => $context->company->getId()->toRfc4122(),
            'recorded' => ExpenseStatus::Recorded->value,
            'paid' => ExpenseStatus::Paid->value,
            'from' => $period->from->format('Y-m-d'),
            'to' => $period->to->format('Y-m-d'),
        ];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
