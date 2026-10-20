<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Reports;

use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
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
 * What each customer kept back at source on the period's issued documents, the sums the home shows as withheld: the
 * amount the company is owed a certificate for, customer by customer, which is what is chased at the end of a quarter.
 * A credit note gives back what its invoice kept.
 */
final readonly class WithholdingSufferedReport implements DeclaresReport
{
    private const string WITHHELD = 'i.company_id = :company AND i.status <> :cancelled AND i.amount_due IS NOT NULL
          AND i.issue_date BETWEEN :from AND :to AND i.withholding_amount <> 0';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'withholding-suffered';
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
            new ReportColumn('gross', ReportColumnKind::Amount),
            new ReportColumn('withheld', ReportColumnKind::Amount),
            new ReportColumn('documents', ReportColumnKind::Count),
        ];
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        [$where, $params] = $this->withheld($context, $period);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id AS customer_id, c.name, SUM(i.total_gross) AS gross, SUM(i.withholding_amount) AS withheld, COUNT(*) AS documents
               FROM invoice i '.KeptRecords::join('customer', 'i.customer_id', 'c', 'cn', required: true).'
              WHERE '.$where.'
              GROUP BY c.id, c.name
              ORDER BY SUM(i.withholding_amount) DESC, c.name, c.id',
            $params,
        );

        return array_map(static fn (array $row): ReportRow => new ReportRow(
            self::text($row['customer_id']),
            [
                'customer' => self::text($row['name']),
                'gross' => self::text($row['gross']),
                'withheld' => self::text($row['withheld']),
                'documents' => (int) self::text($row['documents']),
            ],
            // No list narrows by what was withheld: the row opens its own documents, each with what it kept back.
            new ReportDrill(),
        ), $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        if (!Uuid::isValid($rowKey)) {
            return [];
        }
        [$where, $params] = $this->withheld($context, $period);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.number, i.customer_snapshot ->> \'name\' AS party, i.issue_date, i.withholding_amount
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
            self::text($row['withholding_amount']),
        ), $rows);
    }

    /** @return array{string, array<string, string>} */
    private function withheld(ReportContext $context, ReportPeriod $period): array
    {
        $params = [
            'company' => $context->company->getId()->toRfc4122(),
            'cancelled' => InvoiceStatus::Cancelled->value,
            'from' => $period->from->format('Y-m-d'),
            'to' => $period->to->format('Y-m-d'),
        ];
        if (null === $context->establishmentId) {
            return [self::WITHHELD, $params];
        }

        return [self::WITHHELD.' AND i.establishment_id = :establishment', $params + ['establishment' => $context->establishmentId]];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
