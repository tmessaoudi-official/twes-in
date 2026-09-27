<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Fiscal\Domain\TaxFamily;
use App\Module\Invoices\Application\InvoiceSummarySource;
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
            'SELECT TO_CHAR(p.payment_date, \'YYYY-MM\') AS month, SUM(p.amount) AS amount FROM payment p JOIN invoice i ON i.id = p.invoice_id
             WHERE i.company_id = :company AND i.document_type = :invoice AND i.status <> :cancelled AND i.amount_due IS NOT NULL
               AND p.payment_date >= :from
             GROUP BY 1',
            ['company' => $companyId->toRfc4122(), 'invoice' => InvoiceType::Invoice->value, 'cancelled' => InvoiceStatus::Cancelled->value, 'from' => $from->format('Y-m-d')],
        );
        $paid = [];
        foreach ($rows as $row) {
            $paid[self::text($row['month'])] = self::text($row['amount']);
        }

        return $paid;
    }

    public function vatIssued(Uuid $companyId, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t ->> \'code\' AS code, t ->> \'rate\' AS rate, SUM((t ->> \'amount\')::numeric) AS amount
             FROM invoice i CROSS JOIN LATERAL jsonb_array_elements(i.tax_breakdown) t
             WHERE i.company_id = :company AND i.status <> :cancelled AND i.amount_due IS NOT NULL
               AND i.issue_date >= :from AND i.issue_date < :until
               AND EXISTS (
                   SELECT 1 FROM invoice_line l JOIN invoice_line_tax lt ON lt.line_id = l.id JOIN tax_component c ON c.id = lt.tax_component_id
                   WHERE l.invoice_id = i.id AND lt.code = t ->> \'code\' AND c.family = :vat
               )
             GROUP BY 1, 2',
            ['company' => $companyId->toRfc4122(), 'cancelled' => InvoiceStatus::Cancelled->value, 'from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d'), 'vat' => TaxFamily::Vat->value],
        );

        return array_map(static fn (array $row): array => ['code' => self::text($row['code']), 'rate' => self::text($row['rate']), 'amount' => self::text($row['amount'])], $rows);
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
