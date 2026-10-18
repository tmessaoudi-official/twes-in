<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Reports;

use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportColumn;
use App\Module\Reports\Application\ReportColumnKind;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportDrill;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\ReportRow;
use Doctrine\DBAL\Connection;

/**
 * Each product's stock over the period, in its own unit: what was on hand when the period began, what came in and went
 * out by why it moved, and what was on hand when it ended. Every product that moved in the period, or held stock when it
 * began, is a row. Sold is what invoices and delivery notes took, less what a cancelled delivery note put back; returned
 * is what credit notes brought back; counted is what counts corrected, either way. Closing is opening plus every movement
 * of the period, so it stays the stock however the columns between split it.
 *
 * The days are the company's own: a movement at 00:30 in Tunis belongs to that day. Narrowed to an establishment, its
 * locations alone count. A move between two places stays inside one establishment, so it is in no column and leaves the
 * closing as it was; goods sent to another establishment will need a column once they can be, with their transport
 * document.
 */
final readonly class StockMovementsReport implements DeclaresReport
{
    public function __construct(private Connection $connection, private TopLocations $tops)
    {
    }

    public function key(): string
    {
        return 'stock-movements';
    }

    public function module(): string
    {
        return InventoryModule::KEY;
    }

    public function permission(): string
    {
        return StockPermission::READ;
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
            new ReportColumn('product', ReportColumnKind::Label),
            new ReportColumn('reference', ReportColumnKind::Label),
            new ReportColumn('unit', ReportColumnKind::Label),
            new ReportColumn('opening', ReportColumnKind::Quantity),
            new ReportColumn('received', ReportColumnKind::Quantity),
            new ReportColumn('sold', ReportColumnKind::Quantity),
            new ReportColumn('returned', ReportColumnKind::Quantity),
            new ReportColumn('lost', ReportColumnKind::Quantity),
            new ReportColumn('counted', ReportColumnKind::Quantity),
            new ReportColumn('closing', ReportColumnKind::Quantity),
        ];
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        $zone = new \DateTimeZone($context->company->getTimezone());
        $utc = new \DateTimeZone('UTC');
        $params = [
            'company' => $context->company->getId()->toRfc4122(),
            'start' => new \DateTimeImmutable($period->from->format('Y-m-d'), $zone)->setTimezone($utc)->format('Y-m-d H:i:s'),
            'before' => new \DateTimeImmutable($period->to->format('Y-m-d'), $zone)->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
            'receipt' => StockMovement::SOURCE_RECEIPT,
            'invoice' => StockMovement::SOURCE_INVOICE,
            'delivery' => StockMovement::SOURCE_DELIVERY_NOTE,
            'credit' => StockMovement::SOURCE_CREDIT_NOTE,
            'loss' => StockMovement::SOURCE_LOSS,
            'count' => StockMovement::SOURCE_COUNT,
        ];
        $where = 'm.company_id = :company AND m.at < :before';
        $join = '';
        if (null !== $context->establishmentId) {
            $join = 'JOIN stock_location sl ON sl.id = m.location_id';
            $where .= ' AND sl.establishment_id = :establishment';
            $params['establishment'] = $context->establishmentId;
        }
        $in = 'm.at >= :start';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.product_id, p.name, p.reference, u.code AS unit, g.opening, g.received, g.sold, g.returned, g.lost,
                    g.counted, g.opening + g.moved AS closing
               FROM (
                    SELECT m.product_id,
                           COALESCE(SUM(m.quantity) FILTER (WHERE m.at < :start), 0.000) AS opening,
                           COALESCE(SUM(m.quantity) FILTER (WHERE '.$in.' AND m.source_type = :receipt), 0.000) AS received,
                           COALESCE(-SUM(m.quantity) FILTER (WHERE '.$in.' AND m.source_type IN (:invoice, :delivery)), 0.000) AS sold,
                           COALESCE(SUM(m.quantity) FILTER (WHERE '.$in.' AND m.source_type = :credit), 0.000) AS returned,
                           COALESCE(-SUM(m.quantity) FILTER (WHERE '.$in.' AND m.source_type = :loss), 0.000) AS lost,
                           COALESCE(SUM(m.quantity) FILTER (WHERE '.$in.' AND m.source_type = :count), 0.000) AS counted,
                           COALESCE(SUM(m.quantity) FILTER (WHERE '.$in.'), 0.000) AS moved,
                           COUNT(*) FILTER (WHERE '.$in.') AS movements
                      FROM stock_movement m '.$join.'
                     WHERE '.$where.'
                     GROUP BY m.product_id
               ) g
               JOIN product p ON p.id = g.product_id
               JOIN unit u ON u.id = p.unit_id
              WHERE g.movements > 0 OR g.opening <> 0
              ORDER BY p.reference, p.id',
            $params,
        );
        $located = [] === $rows ? [] : $this->tops->filters($context);
        $from = $period->from->format('Y-m-d');
        $to = $period->to->format('Y-m-d');

        return array_map(static function (array $row) use ($located, $from, $to): ReportRow {
            $product = self::text($row['product_id']);
            $cells = [
                'product' => self::text($row['name']),
                'reference' => self::text($row['reference']),
                'unit' => self::text($row['unit']),
                'opening' => self::text($row['opening']),
                'received' => self::text($row['received']),
                'sold' => self::text($row['sold']),
                'returned' => self::text($row['returned']),
                'lost' => self::text($row['lost']),
                'counted' => self::text($row['counted']),
                'closing' => self::text($row['closing']),
            ];
            $filters = ['product' => $product, 'movedAt.from' => $from, 'movedAt.to' => $to, ...$located];

            return new ReportRow($product, $cells, new ReportDrill('stock-movements', $filters));
        }, $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        // Every row opens the movements list, which shows each movement and what it came from.
        return [];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
