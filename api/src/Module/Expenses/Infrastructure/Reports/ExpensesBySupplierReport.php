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
use App\Module\Reports\Application\ReportDocument;
use App\Module\Reports\Application\ReportDrill;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\ReportRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * What the company spent over the period, by whom it paid: a vendor on record is one row, keyed by its id; a payee
 * written in words is one row per name, written however its case and spaces went, keyed `payee:` and the name's
 * digest; an expense naming neither is one row, keyed `none`. Every expense recorded in the books counts, paid or not,
 * on the day its bill bears. An expense is kept in the company's currency, which a company keeps for good, so no sum
 * mixes two.
 */
final readonly class ExpensesBySupplierReport implements DeclaresReport
{
    private const string RECORDED = 'e.company_id = :company AND e.status IN (:recorded, :paid) AND e.expense_date BETWEEN :from AND :to';

    /** The vendor an expense is counted under: the one it names, or the one kept when that one was merged away. */
    private const string STANDING = 'LEFT JOIN vendor named ON named.id = e.vendor_id LEFT JOIN vendor v ON v.id = COALESCE(named.merged_into_id, named.id)';

    /** The same name whatever its case and spaces: « Société  X » and « société x » are one payee. */
    private const string PAYEE = 'lower(regexp_replace(btrim(e.payee), \'\s+\', \' \', \'g\'))';

    private const string PAYEE_KEY = '/^payee:([0-9a-f]{32})$/';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'expenses-by-supplier';
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
            new ReportColumn('supplier', ReportColumnKind::Label),
            new ReportColumn('net', ReportColumnKind::Amount),
            new ReportColumn('taxes', ReportColumnKind::Amount),
            new ReportColumn('gross', ReportColumnKind::Amount),
            new ReportColumn('expenses', ReportColumnKind::Count),
        ];
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT vendor_id, payee_key, COALESCE(MIN(vendor_name), MIN(payee)) AS name,
                    SUM(amount_net) AS net, SUM(taxes) AS taxes, SUM(amount_gross) AS gross, COUNT(*) AS expenses
               FROM (SELECT v.id AS vendor_id, v.name AS vendor_name, btrim(e.payee) AS payee,
                            CASE WHEN e.vendor_id IS NULL AND btrim(e.payee) <> \'\' THEN md5('.self::PAYEE.') END AS payee_key,
                            e.amount_net, e.tax_amount + e.fixed_amount AS taxes, e.amount_gross
                       FROM expense e
                       '.self::STANDING.'
                      WHERE '.self::RECORDED.') spent
              GROUP BY vendor_id, payee_key
              ORDER BY (vendor_id IS NULL AND payee_key IS NULL), SUM(amount_gross) DESC, name, vendor_id, payee_key',
            $this->params($context, $period),
        );
        $from = $period->from->format('Y-m-d');
        $to = $period->to->format('Y-m-d');

        return array_map(static function (array $row) use ($from, $to): ReportRow {
            $vendor = null === $row['vendor_id'] ? null : self::text($row['vendor_id']);
            $payee = null === $row['payee_key'] ? null : self::text($row['payee_key']);
            $key = null !== $vendor ? $vendor : (null !== $payee ? 'payee:'.$payee : 'none');

            return new ReportRow($key, [
                'supplier' => null === $row['name'] ? null : self::text($row['name']),
                'net' => self::text($row['net']),
                'taxes' => self::text($row['taxes']),
                'gross' => self::text($row['gross']),
                'expenses' => (int) self::text($row['expenses']),
            ], null === $vendor
                // The expenses list narrows by a vendor on record only: a payee in words lists its own expenses.
                ? new ReportDrill()
                : new ReportDrill('expenses', [
                    'vendor' => $vendor,
                    'status' => ExpenseStatus::Recorded->value.','.ExpenseStatus::Paid->value,
                    'date.from' => $from,
                    'date.to' => $to,
                ]));
        }, $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        $params = $this->params($context, $period);
        if ('none' === $rowKey) {
            $which = 'e.vendor_id IS NULL AND (e.payee IS NULL OR btrim(e.payee) = \'\')';
        } elseif (1 === preg_match(self::PAYEE_KEY, $rowKey, $match)) {
            $which = 'e.vendor_id IS NULL AND e.payee IS NOT NULL AND md5('.self::PAYEE.') = :payee';
            $params += ['payee' => $match[1]];
        } elseif (Uuid::isValid($rowKey)) {
            $which = 'v.id = :vendor';
            $params += ['vendor' => $rowKey];
        } else {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id, e.reference, COALESCE(v.name, e.payee) AS party, e.expense_date, e.amount_gross
               FROM expense e '.self::STANDING.'
              WHERE '.self::RECORDED.' AND '.$which.'
              ORDER BY e.expense_date, e.reference, e.id
              LIMIT :limit',
            $params + ['limit' => self::DOCUMENTS_SHOWN + 1],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): ReportDocument => new ReportDocument(
            'expense',
            self::text($row['id']),
            null === $row['reference'] ? null : self::text($row['reference']),
            null === $row['party'] ? null : self::text($row['party']),
            self::text($row['expense_date']),
            self::text($row['amount_gross']),
        ), $rows);
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
