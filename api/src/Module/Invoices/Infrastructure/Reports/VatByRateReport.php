<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Reports;

use App\Fiscal\Domain\ValueObject\TaxFamily;
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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The VAT collected over the period, by tax and rate, as the issued documents froze it: each document's own breakdown,
 * credit notes taking theirs off, the same sums the home shows as VAT collected. A tax is a VAT when one of the lines it
 * was charged on names a component of the VAT family. A figure to check a return against, never the return itself: the
 * period it falls in is the company's to say.
 */
final readonly class VatByRateReport implements DeclaresReport
{
    /** Each VAT the period's issued documents froze, one row per document and tax, with its base and amount. */
    private const string VAT = 'SELECT i.id AS document, i.number, i.customer_snapshot ->> \'name\' AS party, i.issue_date,
                t ->> \'code\' AS code, t ->> \'rate\' AS rate, (t ->> \'base\')::numeric AS base, (t ->> \'amount\')::numeric AS amount
           FROM invoice i CROSS JOIN LATERAL jsonb_array_elements(i.tax_breakdown) t
          WHERE i.company_id = :company AND '.InvoiceRules::ISSUED.'
            AND i.issue_date BETWEEN :from AND :to %1$s
            AND EXISTS (
                SELECT 1 FROM invoice_line l JOIN invoice_line_tax lt ON lt.line_id = l.id JOIN tax_component c ON c.id = lt.tax_component_id
                 WHERE l.invoice_id = i.id AND lt.code = t ->> \'code\' AND c.family = :vat
            )';

    /** A row's key: the tax's code and its rate, as the breakdown wrote them. */
    private const string KEY = '/^([A-Za-z0-9_.-]{1,32})@(\d{1,3}(?:\.\d{1,3})?)$/';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'vat-by-rate';
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
            new ReportColumn('tax', ReportColumnKind::Label),
            new ReportColumn('rate', ReportColumnKind::Label),
            new ReportColumn('base', ReportColumnKind::Amount),
            new ReportColumn('vat', ReportColumnKind::Amount),
            new ReportColumn('documents', ReportColumnKind::Count),
        ];
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        [$sql, $params] = $this->vat($context, $period);
        // Named as the company names the component, which already says its rate; the code when it has none left.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.code, v.rate, SUM(v.base) AS base, SUM(v.amount) AS amount, COUNT(DISTINCT v.document) AS documents, (
                     SELECT c.name FROM tax_component c
                      WHERE c.company_id = :company AND c.code = v.code AND c.rate = v.rate::numeric
                      ORDER BY c.name LIMIT 1
                 ) AS name
               FROM ('.$sql.') v
              GROUP BY v.code, v.rate
              ORDER BY v.rate::numeric DESC, v.code',
            $params,
        );

        return array_map(static fn (array $row): ReportRow => new ReportRow(
            self::text($row['code']).'@'.self::text($row['rate']),
            [
                'tax' => null === $row['name'] ? self::text($row['code']) : self::text($row['name']),
                'rate' => self::text($row['rate']),
                'base' => self::text($row['base']),
                'vat' => self::text($row['amount']),
                'documents' => (int) self::text($row['documents']),
            ],
            // No list narrows by a tax: the row opens its documents, each with the VAT it froze.
            new ReportDrill(),
        ), $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        if (1 !== preg_match(self::KEY, $rowKey, $key)) {
            return [];
        }
        [$sql, $params] = $this->vat($context, $period);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.document, v.number, v.party, v.issue_date, SUM(v.amount) AS amount
               FROM ('.$sql.') v
              WHERE v.code = :code AND v.rate = :rate
              GROUP BY v.document, v.number, v.party, v.issue_date
              ORDER BY v.issue_date, v.number, v.document
              LIMIT :limit',
            $params + ['code' => $key[1], 'rate' => $key[2], 'limit' => self::DOCUMENTS_SHOWN + 1],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): ReportDocument => new ReportDocument(
            'invoice',
            self::text($row['document']),
            null === $row['number'] ? null : self::text($row['number']),
            null === $row['party'] ? null : self::text($row['party']),
            self::text($row['issue_date']),
            self::text($row['amount']),
        ), $rows);
    }

    /** @return array{string, array<string, string>} */
    private function vat(ReportContext $context, ReportPeriod $period): array
    {
        $params = [
            'company' => $context->company->getId()->toRfc4122(),
            'cancelled' => InvoiceRules::CANCELLED,
            'vat' => TaxFamily::Vat->value,
            'from' => $period->from->format('Y-m-d'),
            'to' => $period->to->format('Y-m-d'),
        ];
        if (null === $context->establishmentId) {
            return [\sprintf(self::VAT, ''), $params];
        }

        return [\sprintf(self::VAT, 'AND i.establishment_id = :establishment'), $params + ['establishment' => $context->establishmentId]];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
