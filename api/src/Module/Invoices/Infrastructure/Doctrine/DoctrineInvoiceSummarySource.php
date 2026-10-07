<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Fiscal\Domain\TaxFamily;
use App\Module\Invoices\Application\InvoiceSummarySource;
use App\Module\Invoices\Domain\CreditEntryKind;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The home page's sums in SQL. Plain SQL is not scoped by the company filter, so every statement names the company
 * itself. An issued document is one whose amount due issuing wrote.
 */
final readonly class DoctrineInvoiceSummarySource implements InvoiceSummarySource
{
    private const string OPEN = <<<'SQL'
        i.company_id = :company AND i.document_type = :invoice AND i.status IN (:open) AND i.amount_due IS NOT NULL
        SQL;

    private Connection $connection;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->connection = $entityManager->getConnection();
    }

    public function openByDueDate(Uuid $companyId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.due_date, SUM(i.amount_due) AS amount, COUNT(*) AS n FROM invoice i WHERE '.self::OPEN.' GROUP BY i.due_date',
            ...$this->open($companyId),
        );

        return array_map(static fn (array $row): array => [
            'dueDate' => null === $row['due_date'] ? null : self::day($row['due_date']),
            'amount' => self::text($row['amount']),
            'count' => (int) self::text($row['n']),
        ], $rows);
    }

    public function firstDueBy(Uuid $companyId, \DateTimeImmutable $day, int $limit): array
    {
        [$parameters, $types] = $this->open($companyId);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.number, i.customer_snapshot ->> \'name\' AS customer_name, i.due_date, i.amount_due FROM invoice i
             WHERE '.self::OPEN.' AND i.due_date <= :day
             ORDER BY i.due_date, i.number COLLATE "C" LIMIT '.max(0, $limit),
            $parameters + ['day' => $day->format('Y-m-d')],
            $types,
        );

        return array_map(static fn (array $row): array => [
            'invoiceId' => self::text($row['id']),
            'number' => self::text($row['number']),
            'customerName' => null === $row['customer_name'] ? '' : self::text($row['customer_name']),
            'dueDate' => self::day($row['due_date']),
            'amountDue' => self::text($row['amount_due']),
        ], $rows);
    }

    public function paidByMonth(Uuid $companyId, \DateTimeImmutable $from): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT TO_CHAR(day, \'YYYY-MM\') AS month, SUM(amount) AS amount FROM ('.self::MONEY_RECEIVED.') money
             WHERE day >= :from
             GROUP BY 1',
            [...self::moneyReceived($companyId), 'from' => $from->format('Y-m-d')],
        );
        $paid = [];
        foreach ($rows as $row) {
            $paid[self::text($row['month'])] = self::text($row['amount']);
        }

        return $paid;
    }

    public function paidBetween(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): string
    {
        return self::text($this->connection->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM ('.self::MONEY_RECEIVED.') money WHERE day >= :from AND day < :until',
            [...self::moneyReceived($companyId), 'from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d')],
        ));
    }

    /**
     * The money the company received, each on its own day (docs/SPEC.md § 7, audit E-14): payments into its issued
     * invoices except those made of the customer's credit, which was money already received; what was paid beyond an
     * invoice on the day it came in; and what was refunded, taken off on the day it left.
     */
    private const string MONEY_RECEIVED = 'SELECT p.payment_date AS day, p.amount FROM payment p JOIN invoice i ON i.id = p.invoice_id
         WHERE i.company_id = :company AND i.document_type = :invoice AND i.status <> :cancelled AND i.amount_due IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM customer_credit_entry applied WHERE applied.payment_id = p.id)
         UNION ALL
         SELECT e.entry_date, e.amount FROM customer_credit_entry e WHERE e.company_id = :company AND e.kind IN (:overpayment, :refunded)';

    /** @return array<string, string> */
    private static function moneyReceived(Uuid $companyId): array
    {
        return [
            'company' => $companyId->toRfc4122(),
            'invoice' => InvoiceType::Invoice->value,
            'cancelled' => InvoiceStatus::Cancelled->value,
            'overpayment' => CreditEntryKind::Overpayment->value,
            'refunded' => CreditEntryKind::Refunded->value,
        ];
    }

    public function invoicedBetween(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        // What is invoiced is each document's own net, after its discount. A line's net comes before the document's
        // discount when prices were typed without tax and after it when typed with tax, so the margin's base takes each
        // line at its share of the document's net: the discount is then taken once, whichever way it was typed.
        // A credit note's lines keep their positive quantity while their figures are negative: its cost is counted the same way.
        $row = $this->connection->fetchAssociative(
            'WITH lines AS (
                SELECT i.document_type, i.total_net, l.line_net, l.quantity, l.unit_cost,
                       SUM(l.line_net) OVER (PARTITION BY i.id) AS lines_net,
                       ROW_NUMBER() OVER (PARTITION BY i.id) AS nth
                FROM invoice i JOIN invoice_line l ON l.invoice_id = i.id
                WHERE i.company_id = :company AND i.status <> :cancelled AND i.amount_due IS NOT NULL
                  AND i.issue_date >= :from AND i.issue_date < :until
             )
             SELECT COALESCE(SUM(total_net) FILTER (WHERE nth = 1), 0) AS net,
                    COALESCE(ROUND(SUM(CASE WHEN lines_net = 0 THEN line_net ELSE line_net * total_net / lines_net END) FILTER (WHERE unit_cost IS NOT NULL), 3), 0) AS costed_net,
                    COALESCE(SUM(CASE WHEN document_type = :credit THEN -1 ELSE 1 END * quantity * unit_cost), 0) AS cost,
                    COUNT(*) FILTER (WHERE unit_cost IS NOT NULL) AS costed_lines
             FROM lines',
            ['company' => $companyId->toRfc4122(), 'credit' => InvoiceType::CreditNote->value, 'cancelled' => InvoiceStatus::Cancelled->value, 'from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d')],
        );
        \assert(false !== $row);

        return ['net' => self::text($row['net']), 'costedNet' => self::text($row['costed_net']), 'cost' => self::text($row['cost']), 'costedLines' => (int) self::text($row['costed_lines'])];
    }

    public function withheldBetween(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): string
    {
        $sum = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(withholding_amount), 0) FROM invoice
             WHERE company_id = :company AND status <> :cancelled AND amount_due IS NOT NULL
               AND issue_date >= :from AND issue_date < :until',
            ['company' => $companyId->toRfc4122(), 'cancelled' => InvoiceStatus::Cancelled->value, 'from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d')],
        );

        return self::text($sum);
    }

    public function vatIssued(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.code, v.rate, v.amount, (
                 SELECT c.name FROM tax_component c
                 WHERE c.company_id = :company AND c.code = v.code AND c.rate = v.rate::numeric
             ) AS name
             FROM (
             SELECT t ->> \'code\' AS code, t ->> \'rate\' AS rate, SUM((t ->> \'amount\')::numeric) AS amount
             FROM invoice i CROSS JOIN LATERAL jsonb_array_elements(i.tax_breakdown) t
             WHERE i.company_id = :company AND i.status <> :cancelled AND i.amount_due IS NOT NULL
               AND i.issue_date >= :from AND i.issue_date < :until
               AND EXISTS (
                   SELECT 1 FROM invoice_line l JOIN invoice_line_tax lt ON lt.line_id = l.id JOIN tax_component c ON c.id = lt.tax_component_id
                   WHERE l.invoice_id = i.id AND lt.code = t ->> \'code\' AND c.family = :vat
               )
             GROUP BY 1, 2
             ) v',
            ['company' => $companyId->toRfc4122(), 'cancelled' => InvoiceStatus::Cancelled->value, 'from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d'), 'vat' => TaxFamily::Vat->value],
        );

        return array_map(static fn (array $row): array => ['code' => self::text($row['code']), 'rate' => self::text($row['rate']), 'name' => null === $row['name'] ? null : self::text($row['name']), 'amount' => self::text($row['amount'])], $rows);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, ArrayParameterType>} */
    private function open(Uuid $companyId): array
    {
        return [
            ['company' => $companyId->toRfc4122(), 'invoice' => InvoiceType::Invoice->value, 'open' => [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value]],
            ['open' => ArrayParameterType::STRING],
        ];
    }

    private static function day(mixed $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::text($value));
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \UnexpectedValueException('A summary column came back as '.get_debug_type($value).'.');
    }
}
