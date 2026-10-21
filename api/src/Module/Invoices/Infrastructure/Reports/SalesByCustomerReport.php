<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Reports;

use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
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
use App\Shared\Infrastructure\Doctrine\KeptRecords;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * What each customer was invoiced over the period, net of its credit notes, the same sums the home counts as invoiced:
 * every issued document, deposits and credit notes included, each at its own net after its discount. A customer who
 * bought over the period compared and not over this one comes last with nothing against it: who has gone quiet is what
 * this report is read for.
 */
final readonly class SalesByCustomerReport implements DeclaresReport
{
    /** Every issued document of the period: issuing writes the amount due, and a cancelled one was a withdrawn draft. */
    private const string ISSUED = 'i.company_id = :company AND '.InvoiceRules::ISSUED.'
          AND i.issue_date BETWEEN :from AND :to';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'sales-by-customer';
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
            new ReportColumn('net', ReportColumnKind::Amount),
            new ReportColumn('gross', ReportColumnKind::Amount),
            new ReportColumn('documents', ReportColumnKind::Count),
        ];
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        [$where, $params] = $this->issued($context, $period);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id AS customer_id, c.name, SUM(i.total_net) AS net, SUM(i.total_gross) AS gross, COUNT(*) AS documents
               FROM invoice i '.KeptRecords::join('customer', 'i.customer_id', 'c', 'cn', required: true).'
              WHERE '.$where.'
              GROUP BY c.id, c.name
              ORDER BY SUM(i.total_net) DESC, c.name, c.id',
            $params,
        );
        $narrowed = null !== $context->establishmentId;
        $from = $period->from->format('Y-m-d');
        $to = $period->to->format('Y-m-d');

        return array_map(static function (array $row) use ($narrowed, $from, $to): ReportRow {
            $customer = self::text($row['customer_id']);

            // The invoices list's own address: the customer, the period's issue days, every kind of document, and the
            // statuses of an issued one (an overdue one is still issued). The list has no establishment to narrow by, so a
            // report narrowed to one lists its own documents instead.
            return new ReportRow($customer, [
                'customer' => self::text($row['name']),
                'net' => self::text($row['net']),
                'gross' => self::text($row['gross']),
                'documents' => (int) self::text($row['documents']),
            ], $narrowed ? new ReportDrill() : new ReportDrill('invoices', [
                'customer' => $customer,
                'status' => implode(',', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value]),
                'type' => 'invoice,deposit,credit_note',
                'issueDate.from' => $from,
                'issueDate.to' => $to,
            ]));
        }, $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        if (!Uuid::isValid($rowKey)) {
            return [];
        }
        [$where, $params] = $this->issued($context, $period);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.number, i.customer_snapshot ->> \'name\' AS party, i.issue_date, i.total_net
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
            self::text($row['total_net']),
        ), $rows);
    }

    /** @return array{string, array<string, string>} */
    private function issued(ReportContext $context, ReportPeriod $period): array
    {
        $params = [
            'company' => $context->company->getId()->toRfc4122(),
            'cancelled' => InvoiceRules::CANCELLED,
            'from' => $period->from->format('Y-m-d'),
            'to' => $period->to->format('Y-m-d'),
        ];
        if (null === $context->establishmentId) {
            return [self::ISSUED, $params];
        }

        return [self::ISSUED.' AND i.establishment_id = :establishment', $params + ['establishment' => $context->establishmentId]];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
