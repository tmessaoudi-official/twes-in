<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Reports;

use App\Module\Invoices\Application\AgedBalanceSettings;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Invoices\Infrastructure\Doctrine\InvoiceRules;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportColumn;
use App\Module\Reports\Application\ReportColumnKind;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportDocument;
use App\Module\Reports\Application\ReportDrill;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\ReportRow;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Infrastructure\Doctrine\KeptRecords;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * What each customer owes today, by how late: the amount still due on every issued
 * invoice, in the bracket its due day puts it in. It reads today, not a period: what was owed on a past day would have
 * to be rebuilt from every payment and credit note since, and a figure rebuilt is no longer the one the documents hold.
 * A customer's row opens the invoices list on that customer's invoices still to be paid, which add up to its total.
 * Where lateness is cut is the company's: three bounds of days late, AgedBalanceSettings, read in order.
 */
final readonly class AgedBalanceReport implements DeclaresReport
{
    /** Still to be paid: what the invoices list's open statuses show, with something left on it. */
    private const string OPEN = 'i.company_id = :company AND i.document_type = :invoice AND '.InvoiceRules::OPEN;

    public function __construct(private Connection $connection, private ReadSetting $settings)
    {
    }

    public function key(): string
    {
        return 'aged-balance';
    }

    public function module(): string
    {
        return InvoicesModule::KEY;
    }

    public function permission(): string
    {
        return InvoicePermission::READ;
    }

    public function byEstablishment(): bool
    {
        return true;
    }

    public function periodic(): bool
    {
        return false;
    }

    public function columns(ReportContext $context): array
    {
        return [
            new ReportColumn('customer', ReportColumnKind::Label),
            ...array_map(static fn (array $bracket): ReportColumn => new ReportColumn($bracket['key'], ReportColumnKind::Amount, $bracket['params']), $this->brackets($context)),
            new ReportColumn('total', ReportColumnKind::Amount),
            new ReportColumn('invoices', ReportColumnKind::Count),
        ];
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        $brackets = $this->brackets($context);
        $sums = [];
        foreach ($brackets as $bracket) {
            $late = 'GREATEST(CAST(:today AS date) - i.due_date, 0)';
            $condition = match (true) {
                null === $bracket['low'] => \sprintf('%s <= %d', $late, $bracket['high'] ?? 0),
                null === $bracket['high'] => \sprintf('%s > %d', $late, $bracket['low']),
                default => \sprintf('%s > %d AND %s <= %d', $late, $bracket['low'], $late, $bracket['high']),
            };
            $sums[] = \sprintf('SUM(i.amount_due) FILTER (WHERE %s) AS %s', $condition, $bracket['key']);
        }
        $keys = array_column($brackets, 'key');
        [$where, $params] = $this->open($context);
        $params['today'] = $context->today->format('Y-m-d');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id AS customer_id, c.name, '.implode(', ', $sums).', SUM(i.amount_due) AS total, COUNT(*) AS invoices
               FROM invoice i '.KeptRecords::join('customer', 'i.customer_id', 'c', 'cn', required: true).'
              WHERE '.$where.'
              GROUP BY c.id, c.name
              ORDER BY SUM(i.amount_due) DESC, c.name, c.id',
            $params,
            ['open' => ArrayParameterType::STRING],
        );

        $narrowed = null !== $context->establishmentId;

        return array_map(static function (array $row) use ($narrowed, $keys): ReportRow {
            $customer = self::text($row['customer_id']);
            $cells = ['customer' => self::text($row['name'])];
            foreach ($keys as $bracket) {
                $cells[$bracket] = null === $row[$bracket] ? null : self::text($row[$bracket]);
            }
            $cells['total'] = self::text($row['total']);
            $cells['invoices'] = (int) self::text($row['invoices']);

            // The invoices list's own address: its customer, its open statuses (an overdue one is still issued), invoices and
            // deposits but no credit note, and something left to pay, as an invoice issued with nothing to pay keeps its
            // status. The list has no establishment to narrow by, so a report narrowed to one lists its own documents instead.
            return new ReportRow($customer, $cells, $narrowed ? new ReportDrill() : new ReportDrill('invoices', [
                'customer' => $customer,
                'status' => 'issued,partially_paid',
                'type' => 'invoice,deposit',
                'amountDue.min' => '0.001',
            ]));
        }, $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        if (!Uuid::isValid($rowKey)) {
            return [];
        }
        [$where, $params] = $this->open($context);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.number, i.customer_snapshot ->> \'name\' AS party, i.due_date, i.amount_due
               FROM invoice i
              WHERE '.$where.' AND '.KeptRecords::among('i.customer_id', 'customer', ':customer').'
              ORDER BY i.due_date, i.number, i.id
              LIMIT :limit',
            $params + ['customer' => $rowKey, 'limit' => self::DOCUMENTS_SHOWN + 1],
            ['open' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): ReportDocument => new ReportDocument(
            'invoice',
            self::text($row['id']),
            null === $row['number'] ? null : self::text($row['number']),
            null === $row['party'] ? null : self::text($row['party']),
            self::text($row['due_date']),
            self::text($row['amount_due']),
        ), $rows);
    }

    /**
     * The brackets of lateness, in days past the due day: what is not due yet, one bracket up to each of the company's
     * bounds in order, and the open one past the last. Each carries the days its heading says.
     *
     * @return list<array{key: string, low: ?int, high: ?int, params: array<string, int>}>
     */
    private function brackets(ReportContext $context): array
    {
        $bounds = array_values(array_unique(array_map(fn (string $key): int => $this->days($context, $key), [
            AgedBalanceSettings::FIRST_DAYS,
            AgedBalanceSettings::SECOND_DAYS,
            AgedBalanceSettings::THIRD_DAYS,
        ])));
        sort($bounds);
        $brackets = [['key' => 'not_due', 'low' => null, 'high' => 0, 'params' => []]];
        $low = 0;
        foreach ($bounds as $index => $high) {
            $brackets[] = ['key' => 'late_'.($index + 1), 'low' => $low, 'high' => $high, 'params' => ['from' => $low + 1, 'to' => $high]];
            $low = $high;
        }
        $brackets[] = ['key' => 'late_last', 'low' => $low, 'high' => null, 'params' => ['after' => $low]];

        return $brackets;
    }

    private function days(ReportContext $context, string $key): int
    {
        $value = $this->settings->value(new SettingContext($context->company), $key);

        return \is_int($value) ? $value : throw new \LogicException(\sprintf('%s is not an integer.', $key));
    }

    /**
     * The open invoices' condition and its values, narrowed to the establishment asked.
     *
     * @return array{string, array<string, string|list<string>>}
     */
    private function open(ReportContext $context): array
    {
        $params = [
            'company' => $context->company->getId()->toRfc4122(),
            'invoice' => InvoiceType::Invoice->value,
            'open' => InvoiceRules::OPEN_STATUSES,
        ];
        if (null === $context->establishmentId) {
            return [self::OPEN, $params];
        }

        return [self::OPEN.' AND i.establishment_id = :establishment', $params + ['establishment' => $context->establishmentId]];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
