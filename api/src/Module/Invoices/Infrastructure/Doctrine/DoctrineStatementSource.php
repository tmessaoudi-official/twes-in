<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Doctrine;

use App\Module\Invoices\Application\StatementSource;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * One customer's account in SQL. Plain SQL is not scoped by the company filter, so every statement names the company
 * itself. A document is on the account once issuing wrote its amount due (a draft has none, and only a draft is cancelled); what an invoice is worth to the account is
 * its total less what the customer withholds, the same figure its amount due starts from.
 */
final readonly class DoctrineStatementSource implements StatementSource
{
    /**
     * Invoices and credit notes on the day they were issued, payments on the day they were made, and what a credit note
     * gave back of money already paid on the day it did: that leaves the invoice's account for the customer's credit
     * balance or their own hands, so the account does not stay below what the invoices have due.
     */
    private const string ACCOUNT = <<<'SQL'
        SELECT i.issue_date AS day, i.document_type AS kind, i.number, i.id AS document_id, NULL::text AS reference,
               i.total_gross - i.withholding_amount AS amount,
               CASE i.document_type WHEN 'invoice' THEN 1 ELSE 2 END AS rank
          FROM invoice i
         WHERE i.company_id = :company AND i.customer_id = :customer AND i.amount_due IS NOT NULL
        UNION ALL
        SELECT p.payment_date, 'payment', i.number, i.id, p.reference, -p.amount, 3
          FROM payment p JOIN invoice i ON i.id = p.invoice_id
         WHERE i.company_id = :company AND i.customer_id = :customer AND i.amount_due IS NOT NULL
        UNION ALL
        SELECT c.entry_date, 'credit_transfer', i.number, i.id, c.reference, c.amount, 4
          FROM customer_credit_entry c JOIN invoice i ON i.id = c.invoice_id
         WHERE c.company_id = :company AND c.customer_id = :customer AND c.kind = 'credited'
        SQL;

    private Connection $connection;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->connection = $entityManager->getConnection();
    }

    public function balanceBefore(Uuid $companyId, Uuid $customerId, \DateTimeImmutable $day): string
    {
        $sum = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(e.amount), 0) FROM ('.self::ACCOUNT.') e WHERE e.day < :day',
            $this->parameters($companyId, $customerId) + ['day' => $day->format('Y-m-d')],
        );

        return self::text($sum);
    }

    public function entries(Uuid $companyId, Uuid $customerId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.* FROM ('.self::ACCOUNT.') e WHERE e.day >= :from AND e.day <= :to
             ORDER BY e.day, e.rank, e.number COLLATE "C", e.document_id, e.reference COLLATE "C" NULLS FIRST',
            $this->parameters($companyId, $customerId) + ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        );

        return array_map(static fn (array $row): array => [
            'day' => new \DateTimeImmutable(self::text($row['day'])),
            'kind' => self::text($row['kind']),
            'number' => self::text($row['number']),
            'documentId' => self::text($row['document_id']),
            'reference' => null === $row['reference'] ? null : self::text($row['reference']),
            'amount' => self::text($row['amount']),
        ], $rows);
    }

    /** @return array<string, string> */
    private function parameters(Uuid $companyId, Uuid $customerId): array
    {
        return ['company' => $companyId->toRfc4122(), 'customer' => $customerId->toRfc4122()];
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) || \is_int($value) || \is_float($value) ? (string) $value : throw new \UnexpectedValueException('A statement figure came back as neither a string nor a number.');
    }
}
