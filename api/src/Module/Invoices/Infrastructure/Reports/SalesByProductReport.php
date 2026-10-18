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
 * What each product sold over the period, in each unit it was sold in, net of what was credited back: the quantity, and
 * the net each line brings at its share of its document's net, the base the home's margin reads, so a document's
 * discount is taken once whichever way its prices were typed. Rounded per product to the currency's decimals, the rows can
 * differ from the documents' own nets by those roundings.
 *
 * Cost and margin are columns only for a role holding product.cost.read, the one way to cost (docs/SPEC.md § 7,
 * 2026-09-23); they read the cost each line kept when issued, and leave out a line that kept none, which the costed net
 * column says. Lines naming no product are one row, keyed `none`, with no quantity: their units say nothing together.
 */
final readonly class SalesByProductReport implements DeclaresReport
{
    /** A row's key: the product and the unit it was sold in. */
    private const string KEY = '/^([0-9a-f-]{36}):([0-9a-f-]{36})$/';

    public function __construct(private Connection $connection, private CurrencyScales $scales)
    {
    }

    public function key(): string
    {
        return 'sales-by-product';
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
            new ReportColumn('product', ReportColumnKind::Label),
            new ReportColumn('reference', ReportColumnKind::Label),
            new ReportColumn('unit', ReportColumnKind::Label),
            new ReportColumn('quantity', ReportColumnKind::Quantity),
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
        // The lines naming no product group together whatever their unit: NULL groups with NULL, and their unit is dropped.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.product_id, g.unit_id, p.name, p.reference, u.code AS unit, g.quantity, g.net, g.costed_net, g.cost
               FROM (
                    SELECT s.product_id, CASE WHEN s.product_id IS NULL THEN NULL ELSE s.unit_id END AS unit_id,
                           SUM(s.quantity) AS quantity,
                           ROUND(SUM(s.net), :scale) AS net,
                           ROUND(SUM(s.net) FILTER (WHERE s.unit_cost IS NOT NULL), :scale) AS costed_net,
                           ROUND(SUM(s.cost), :scale) AS cost
                      FROM ('.$sql.') s
                     GROUP BY 1, 2
               ) g
               LEFT JOIN product p ON p.id = g.product_id
               LEFT JOIN unit u ON u.id = g.unit_id
              ORDER BY g.product_id IS NULL, g.net DESC, p.name, g.product_id, g.unit_id',
            $params + ['scale' => $this->scales->of($context->company->getCurrency())],
            ['scale' => ParameterType::INTEGER],
        );
        $costing = ($context->may)(ProductPermission::COST_READ);

        return array_map(static function (array $row) use ($costing): ReportRow {
            $named = null !== $row['product_id'];
            $cells = [
                'product' => $named ? self::text($row['name']) : null,
                'reference' => $named ? self::text($row['reference']) : null,
                'unit' => $named ? self::text($row['unit']) : null,
                'quantity' => $named ? self::text($row['quantity']) : null,
                'net' => self::text($row['net']),
            ];
            if ($costing) {
                $cells['costed_net'] = null === $row['costed_net'] ? null : self::decimal($row['costed_net']);
                $cells['cost'] = null === $row['cost'] ? null : self::decimal($row['cost']);
                // Both sums are rounded already, so the margin is exact at the currency's decimals.
                $cells['margin'] = null === $cells['costed_net'] || null === $cells['cost'] ? null : bcsub($cells['costed_net'], $cells['cost'], self::scale($cells['costed_net']));
            }

            // The invoices list cannot narrow to a product, so a product's row lists its own documents.
            return new ReportRow($named ? self::text($row['product_id']).':'.self::text($row['unit_id']) : 'none', $cells, new ReportDrill());
        }, $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        [$sql, $params] = IssuedLines::of($context, $period);
        $types = ['limit' => ParameterType::INTEGER, 'scale' => ParameterType::INTEGER];
        if ('none' === $rowKey) {
            $which = 's.product_id IS NULL';
        } elseif (1 === preg_match(self::KEY, $rowKey, $key) && Uuid::isValid($key[1]) && Uuid::isValid($key[2])) {
            $which = 's.product_id = :product AND s.unit_id = :unit';
            $params += ['product' => $key[1], 'unit' => $key[2]];
        } else {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.document, s.number, s.party, s.issue_date, ROUND(SUM(s.net), :scale) AS net
               FROM ('.$sql.') s
              WHERE '.$which.'
              GROUP BY s.document, s.number, s.party, s.issue_date
              ORDER BY s.issue_date, s.number, s.document
              LIMIT :limit',
            $params + ['scale' => $this->scales->of($context->company->getCurrency()), 'limit' => self::DOCUMENTS_SHOWN + 1],
            $types,
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
