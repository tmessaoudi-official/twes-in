<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Reports;

use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportColumn;
use App\Module\Reports\Application\ReportColumnKind;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportDocument;
use App\Module\Reports\Application\ReportDrill;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\ReportRow;
use App\Shared\Infrastructure\Doctrine\KeptRecords;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * What the company gave back over the period, customer by customer: what its issued credit notes took back, net, and
 * what the discounts of its issued invoices took off, net, as issuing wrote it down. Both are shown as amounts given,
 * so a credit note's negative net reads as what it returned. A document issued before its discounts were kept counts
 * no discount; a credit note giving a discount back is counted in what it took back, not under discounts.
 */
final readonly class CreditNotesAndDiscountsReport implements DeclaresReport
{
    private const string GIVEN = 'i.company_id = :company AND i.status <> :cancelled AND i.amount_due IS NOT NULL
          AND i.issue_date BETWEEN :from AND :to
          AND (i.document_type = :credit OR COALESCE(i.savings, 0) <> 0)';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'credit-notes-and-discounts';
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
            new ReportColumn('customer', ReportColumnKind::Label),
            new ReportColumn('credited', ReportColumnKind::Amount),
            new ReportColumn('credit_notes', ReportColumnKind::Count),
            new ReportColumn('discounted', ReportColumnKind::Amount),
            new ReportColumn('discounted_invoices', ReportColumnKind::Count),
        ];
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        [$where, $params] = $this->given($context, $period);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id AS customer_id, c.name,
                    -SUM(i.total_net) FILTER (WHERE i.document_type = :credit) AS credited,
                    COUNT(*) FILTER (WHERE i.document_type = :credit) AS credit_notes,
                    SUM(i.savings) FILTER (WHERE i.document_type <> :credit) AS discounted,
                    COUNT(*) FILTER (WHERE i.document_type <> :credit) AS discounted_invoices
               FROM invoice i '.KeptRecords::join('customer', 'i.customer_id', 'c', 'cn', required: true).'
              WHERE '.$where.'
              GROUP BY c.id, c.name
              ORDER BY COALESCE(-SUM(i.total_net) FILTER (WHERE i.document_type = :credit), 0)
                     + COALESCE(SUM(i.savings) FILTER (WHERE i.document_type <> :credit), 0) DESC, c.name, c.id',
            $params,
        );

        return array_map(static fn (array $row): ReportRow => new ReportRow(
            self::text($row['customer_id']),
            [
                'customer' => self::text($row['name']),
                // A customer given only discounts has no credit note to sum, and the reverse: nothing, not a zero.
                'credited' => null === $row['credited'] ? null : self::text($row['credited']),
                'credit_notes' => (int) self::text($row['credit_notes']),
                'discounted' => null === $row['discounted'] ? null : self::text($row['discounted']),
                'discounted_invoices' => (int) self::text($row['discounted_invoices']),
            ],
            // No list narrows by a discount: the row opens its own documents, each with what it gave.
            new ReportDrill(),
        ), $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        if (!Uuid::isValid($rowKey)) {
            return [];
        }
        [$where, $params] = $this->given($context, $period);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.number, i.customer_snapshot ->> \'name\' AS party, i.issue_date,
                    CASE WHEN i.document_type = :credit THEN -i.total_net ELSE i.savings END AS given
               FROM invoice i
              WHERE '.$where.' AND '.KeptRecords::among('i.customer_id', 'customer', ':customer').'
              ORDER BY i.issue_date, i.number, i.id
              LIMIT :limit',
            $params + ['customer' => $rowKey, 'limit' => self::DOCUMENTS_SHOWN + 1],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): ReportDocument => new ReportDocument(
            'invoice',
            self::text($row['id']),
            null === $row['number'] ? null : self::text($row['number']),
            null === $row['party'] ? null : self::text($row['party']),
            self::text($row['issue_date']),
            self::text($row['given']),
        ), $rows);
    }

    /** @return array{string, array<string, string>} */
    private function given(ReportContext $context, ReportPeriod $period): array
    {
        $params = [
            'company' => $context->company->getId()->toRfc4122(),
            'cancelled' => InvoiceStatus::Cancelled->value,
            'credit' => InvoiceType::CreditNote->value,
            'from' => $period->from->format('Y-m-d'),
            'to' => $period->to->format('Y-m-d'),
        ];
        if (null === $context->establishmentId) {
            return [self::GIVEN, $params];
        }

        return [self::GIVEN.' AND i.establishment_id = :establishment', $params + ['establishment' => $context->establishmentId]];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
