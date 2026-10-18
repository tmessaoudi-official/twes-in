<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Reports;

use App\Fiscal\Application\CurrencyScales;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
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
use Symfony\Component\Uid\Uuid;

/**
 * What each family of products sold over the period, net of what was credited back, on the same lines and shares as the
 * sales by product: a product's family is its category, named by its whole path (« Outillage › Perceuses »), so a
 * sub-family is a row of its own beside the family above it. Lines whose product has no category, or naming no product,
 * are one row, keyed `none`. No quantity: a family's products are counted in units that say nothing together.
 *
 * Cost and margin are columns only for a role holding product.cost.read, read from the cost each line kept when issued,
 * as in the sales by product.
 */
final readonly class SalesByFamilyReport implements DeclaresReport
{
    /** Each category of the company with its path from the top, read before the lines are grouped. */
    private const string PATHS = 'WITH RECURSIVE path (id, name) AS (
            SELECT c.id, CAST(c.name AS text) FROM product_category c WHERE c.company_id = :company AND c.parent_id IS NULL
             UNION ALL
            SELECT c.id, path.name || \' › \' || c.name FROM product_category c JOIN path ON c.parent_id = path.id
        )';

    public function __construct(private Connection $connection, private CurrencyScales $scales)
    {
    }

    public function key(): string
    {
        return 'sales-by-family';
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
        $columns = [
            new ReportColumn('family', ReportColumnKind::Label),
            new ReportColumn('net', ReportColumnKind::Amount),
        ];
        if (!($context->may)(ProductPermission::COST_READ)) {
            return $columns;
        }

        return [
            ...$columns,
            new ReportColumn('costed_net', ReportColumnKind::Amount),
            new ReportColumn('cost', ReportColumnKind::Amount),
            new ReportColumn('margin', ReportColumnKind::Amount),
        ];
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        [$sql, $params] = IssuedLines::of($context, $period);
        $rows = $this->connection->fetchAllAssociative(
            self::PATHS.'
            SELECT g.category_id, path.name, g.net, g.costed_net, g.cost
              FROM (
                   SELECT p.category_id,
                          ROUND(SUM(s.net), :scale) AS net,
                          ROUND(SUM(s.net) FILTER (WHERE s.unit_cost IS NOT NULL), :scale) AS costed_net,
                          ROUND(SUM(s.cost), :scale) AS cost
                     FROM ('.$sql.') s
                     LEFT JOIN product p ON p.id = s.product_id
                    GROUP BY p.category_id
              ) g
              LEFT JOIN path ON path.id = g.category_id
             ORDER BY g.category_id IS NULL, g.net DESC, path.name, g.category_id',
            $params + ['scale' => $this->scales->of($context->company->getCurrency())],
            ['scale' => ParameterType::INTEGER],
        );
        $costing = ($context->may)(ProductPermission::COST_READ);

        return array_map(static function (array $row) use ($costing): ReportRow {
            $named = null !== $row['category_id'];
            $cells = [
                'family' => $named ? self::text($row['name']) : null,
                'net' => self::text($row['net']),
            ];
            if ($costing) {
                $cells['costed_net'] = null === $row['costed_net'] ? null : self::decimal($row['costed_net']);
                $cells['cost'] = null === $row['cost'] ? null : self::decimal($row['cost']);
                // Both sums are rounded already, so the margin is exact at the currency's decimals.
                $cells['margin'] = null === $cells['costed_net'] || null === $cells['cost'] ? null : bcsub($cells['costed_net'], $cells['cost'], self::scale($cells['costed_net']));
            }

            // The invoices list cannot narrow to a family, so a family's row lists its own documents.
            return new ReportRow($named ? self::text($row['category_id']) : 'none', $cells, new ReportDrill());
        }, $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        [$sql, $params] = IssuedLines::of($context, $period);
        if ('none' === $rowKey) {
            $which = 'p.category_id IS NULL';
        } elseif (Uuid::isValid($rowKey)) {
            $which = 'p.category_id = :category';
            $params += ['category' => $rowKey];
        } else {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.document, s.number, s.party, s.issue_date, ROUND(SUM(s.net), :scale) AS net
               FROM ('.$sql.') s
               LEFT JOIN product p ON p.id = s.product_id
              WHERE '.$which.'
              GROUP BY s.document, s.number, s.party, s.issue_date
              ORDER BY s.issue_date, s.number, s.document
              LIMIT :limit',
            $params + ['scale' => $this->scales->of($context->company->getCurrency()), 'limit' => self::DOCUMENTS_SHOWN + 1],
            ['limit' => ParameterType::INTEGER, 'scale' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): ReportDocument => new ReportDocument(
            'invoice',
            self::text($row['document']),
            null === $row['number'] ? null : self::text($row['number']),
            null === $row['party'] ? null : self::text($row['party']),
            self::text($row['issue_date']),
            self::text($row['net']),
        ), $rows);
    }

    private static function scale(string $decimal): int
    {
        $point = strpos($decimal, '.');

        return false === $point ? 0 : \strlen($decimal) - $point - 1;
    }

    /** @return numeric-string */
    private static function decimal(mixed $value): string
    {
        return \is_string($value) && is_numeric($value) ? $value : throw new \LogicException('A figure the query names is not a number.');
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
