<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Reports;

use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportPeriod;
use App\Shared\Infrastructure\Doctrine\KeptRecords;

/**
 * Every line of a period's issued documents, as the sales reports read them: its share of its document's net, the base
 * the home's margin reads, so a document's discount is taken once whichever way its prices were typed, and its signed
 * cost. A credit note's lines keep a positive quantity while their figures are negative, so its quantity and cost are
 * turned over. A line naming a product merged away is counted under the product kept.
 */
final class IssuedLines
{
    private static function lines(): string
    {
        return 'SELECT i.id AS document, i.number, i.customer_snapshot ->> \'name\' AS party, i.issue_date,
                '.KeptRecords::id('named').' AS product_id, l.unit_id, l.unit_cost,
                CASE WHEN i.document_type = :credit THEN -1 ELSE 1 END * l.quantity AS quantity,
                CASE WHEN SUM(l.line_net) OVER (PARTITION BY i.id) = 0 THEN l.line_net
                     ELSE l.line_net * i.total_net / SUM(l.line_net) OVER (PARTITION BY i.id) END AS net,
                CASE WHEN i.document_type = :credit THEN -1 ELSE 1 END * l.quantity * l.unit_cost AS cost
           FROM invoice i JOIN invoice_line l ON l.invoice_id = i.id
           LEFT JOIN product named ON named.id = l.product_id
          WHERE i.company_id = :company AND i.status <> :cancelled AND i.amount_due IS NOT NULL
            AND i.issue_date BETWEEN :from AND :to %1$s';
    }

    /**
     * The lines' query, to be read as a subquery, and its values, narrowed to the establishment asked.
     *
     * @return array{string, array<string, string>}
     */
    public static function of(ReportContext $context, ReportPeriod $period): array
    {
        $params = [
            'company' => $context->company->getId()->toRfc4122(),
            'credit' => InvoiceType::CreditNote->value,
            'cancelled' => InvoiceStatus::Cancelled->value,
            'from' => $period->from->format('Y-m-d'),
            'to' => $period->to->format('Y-m-d'),
        ];
        if (null === $context->establishmentId) {
            return [\sprintf(self::lines(), ''), $params];
        }

        return [\sprintf(self::lines(), 'AND i.establishment_id = :establishment'), $params + ['establishment' => $context->establishmentId]];
    }
}
