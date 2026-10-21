<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Reports;

use App\Module\Invoices\Domain\ValueObject\CreditEntryKind;
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
use App\Shared\Domain\ValueObject\PaymentMethod;
use App\Shared\Infrastructure\Doctrine\KeptRecords;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The money taken each day, by means of payment (the first report): the same money
 * the home counts as collected, so the two never disagree. Payments into issued invoices, except those made of a
 * customer's credit, which was money already taken; what was paid beyond an invoice, whose means the company did not
 * record apart, in a column of its own; and what was refunded, on the day it left.
 *
 * Every day of the period is a row, a day with nothing taken included: a day without takings is what a shop looks for.
 * A row is keyed by its place in the period, so the tenth day is read against the tenth day of the period compared.
 */
final readonly class TakingsReport implements DeclaresReport
{
    /**
     * The money taken, one row per payment or credit entry, with its means: what every figure and document reads. An
     * invoice's party is the name it was issued to; a credit entry's is the customer kept, as a merge leaves the entry
     * naming the customer merged away.
     */
    private const string MONEY = 'SELECT p.payment_date AS day, p.method AS means, p.amount, i.id AS document, i.number, i.customer_snapshot ->> \'name\' AS party
           FROM payment p JOIN invoice i ON i.id = p.invoice_id
          WHERE i.company_id = :company AND i.document_type = :invoice AND '.InvoiceRules::ISSUED.'
            AND NOT EXISTS (SELECT 1 FROM customer_credit_entry applied WHERE applied.payment_id = p.id)
            AND p.payment_date BETWEEN :from AND :to %1$s
         UNION ALL
         SELECT e.entry_date, CASE WHEN e.kind = :overpayment THEN \'overpaid\' ELSE \'refunded\' END, e.amount, e.invoice_id, i.number, c.name
           FROM customer_credit_entry e
           %2$s
           LEFT JOIN invoice i ON i.id = e.invoice_id
          WHERE e.company_id = :company AND e.kind IN (:overpayment, :refunded) AND e.entry_date BETWEEN :from AND :to %1$s';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'takings';
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
        return true;
    }

    public function columns(ReportContext $context): array
    {
        return [
            new ReportColumn('day', ReportColumnKind::Day),
            ...array_map(static fn (string $means): ReportColumn => new ReportColumn($means, ReportColumnKind::Amount), self::means()),
            new ReportColumn('total', ReportColumnKind::Amount),
        ];
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        [$sql, $params] = $this->money($context, $period);
        $taken = [];
        // The day's total is summed by the database beside its means, so no figure is added up again here.
        $grouped = 'SELECT day, CASE WHEN GROUPING(means) = 1 THEN \'total\' ELSE means END AS means, SUM(amount) AS amount
                      FROM ('.$sql.') money GROUP BY GROUPING SETS ((day, means), (day))';
        foreach ($this->connection->fetchAllAssociative($grouped, $params) as $row) {
            $taken[self::text($row['day'])][self::text($row['means'])] = self::text($row['amount']);
        }

        $rows = [];
        for ($day = $period->from, $place = 0; $day <= $period->to; $day = $day->modify('+1 day'), ++$place) {
            $on = $taken[$day->format('Y-m-d')] ?? [];
            $cells = ['day' => $day->format('Y-m-d')];
            foreach ([...self::means(), 'total'] as $column) {
                $cells[$column] = $on[$column] ?? null;
            }
            $rows[] = new ReportRow('d'.$place, $cells, [] === $on ? null : new ReportDrill());
        }

        return $rows;
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        if (1 !== preg_match('/^d(\d{1,3})$/', $rowKey, $place)) {
            return [];
        }
        $day = $period->from->modify(\sprintf('+%d days', (int) $place[1]));
        if ($day > $period->to) {
            return [];
        }
        [$sql, $params] = $this->money($context, new ReportPeriod($day, $day));

        return array_map(static fn (array $row): ReportDocument => new ReportDocument(
            'invoice',
            self::text($row['document']),
            null === $row['number'] ? null : self::text($row['number']),
            null === $row['party'] ? null : self::text($row['party']),
            self::text($row['day']),
            self::text($row['amount']),
        ), $this->connection->fetchAllAssociative(
            // Every entry names the invoice it arose on, an overpayment and a refund included; the guard keeps a row
            // naming none out of the documents before the limit counts, as it has nothing to open.
            'SELECT * FROM ('.$sql.') money WHERE document IS NOT NULL ORDER BY means, number, document LIMIT :limit',
            $params + ['limit' => self::DOCUMENTS_SHOWN + 1],
            ['limit' => ParameterType::INTEGER],
        ));
    }

    /** @return list<string> the columns of money, the means a payment records, then what the company did not record apart */
    private static function means(): array
    {
        return [...array_map(static fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases()), 'overpaid', 'refunded'];
    }

    /** @return array{string, array<string, string>} */
    private function money(ReportContext $context, ReportPeriod $period): array
    {
        $params = [
            'company' => $context->company->getId()->toRfc4122(),
            'invoice' => InvoiceType::Invoice->value,
            'cancelled' => InvoiceRules::CANCELLED,
            'overpayment' => CreditEntryKind::Overpayment->value,
            'refunded' => CreditEntryKind::Refunded->value,
            'from' => $period->from->format('Y-m-d'),
            'to' => $period->to->format('Y-m-d'),
        ];
        if (null === $context->establishmentId) {
            return [\sprintf(self::MONEY, '', self::party()), $params];
        }

        return [\sprintf(self::MONEY, 'AND i.establishment_id = :establishment', self::party()), $params + ['establishment' => $context->establishmentId]];
    }

    private static function party(): string
    {
        return KeptRecords::join('customer', 'e.customer_id', 'c', 'cn', required: true);
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
