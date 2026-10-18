<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Reports;

use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\ValueObject\StockMovementKind;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Module\Invoices\Application\InvoiceWatchSettings;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportColumn;
use App\Module\Reports\Application\ReportColumnKind;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportDrill;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\ReportRow;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use BcMath\Number;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * What is on hand today and has stopped selling, quietest first: every product with stock whose last sale, by an invoice
 * or a delivery note, is at least as old as the company's own « not sold for » days, the threshold « À surveiller »
 * reads too; a product never sold counts its days from the first day it came in. It reads today, as the stock does, and
 * takes no period.
 *
 * Its value is a column only for a role holding product.cost.read, the one way to cost: what is on hand at the product's
 * average cost, as the stock valuation counts it; a product with nothing valued shows none. Narrowed to an establishment,
 * its locations alone count, valued at the same average, which is the company's for the product wherever it lies.
 */
final readonly class DeadStockReport implements DeclaresReport
{
    public function __construct(private Connection $connection, private KeepStock $stock, private TopLocations $tops, private ReadSetting $settings)
    {
    }

    public function key(): string
    {
        return 'dead-stock';
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
        return false;
    }

    public function columns(ReportContext $context): array
    {
        $columns = [
            new ReportColumn('product', ReportColumnKind::Label),
            new ReportColumn('reference', ReportColumnKind::Label),
            new ReportColumn('unit', ReportColumnKind::Label),
            new ReportColumn('on_hand', ReportColumnKind::Quantity),
            new ReportColumn('last_sold', ReportColumnKind::Day),
            new ReportColumn('quiet_days', ReportColumnKind::Days),
        ];
        if (($context->may)(ProductPermission::COST_READ)) {
            $columns[] = new ReportColumn('value', ReportColumnKind::Amount);
        }

        return $columns;
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        $params = [
            'company' => $context->company->getId()->toRfc4122(),
            'zone' => $context->company->getTimezone(),
            'today' => $context->today->format('Y-m-d'),
            'quiet' => $this->quietDays($context),
            'invoice' => StockMovement::SOURCE_INVOICE,
            'delivery' => StockMovement::SOURCE_DELIVERY_NOTE,
            'out' => StockMovementKind::Out->value,
            'in' => StockMovementKind::In->value,
        ];
        $join = '';
        $where = 'm.company_id = :company';
        if (null !== $context->establishmentId) {
            $join = 'JOIN stock_location sl ON sl.id = m.location_id';
            $where .= ' AND sl.establishment_id = :establishment';
            $params['establishment'] = $context->establishmentId;
        }
        // A moment stored in UTC, read as the company's own day.
        $day = '(m.at AT TIME ZONE \'UTC\' AT TIME ZONE :zone)::date';
        // A delivery note cancelled put its goods back under its own source: they were never sold.
        $sold = 'm.kind = :out AND m.source_type IN (:invoice, :delivery) AND NOT EXISTS (
                    SELECT 1 FROM stock_movement back
                     WHERE back.source_type = m.source_type AND back.source_id = m.source_id AND back.product_id = m.product_id
                       AND back.location_id = m.location_id AND back.lot_id IS NOT DISTINCT FROM m.lot_id AND back.kind = :in)';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.product_id, p.name, p.reference, u.code AS unit, g.on_hand, g.last_sold,
                    CAST(:today AS date) - COALESCE(g.last_sold, g.first_in) AS quiet_days
               FROM (
                    SELECT m.product_id, SUM(m.quantity) AS on_hand,
                           MAX('.$day.') FILTER (WHERE '.$sold.') AS last_sold,
                           MIN('.$day.') FILTER (WHERE m.quantity > 0) AS first_in
                      FROM stock_movement m '.$join.'
                     WHERE '.$where.'
                     GROUP BY m.product_id
                    HAVING SUM(m.quantity) > 0
               ) g
               JOIN product p ON p.id = g.product_id
               JOIN unit u ON u.id = p.unit_id
              WHERE CAST(:today AS date) - COALESCE(g.last_sold, g.first_in) >= :quiet
              ORDER BY quiet_days DESC, p.reference, p.id',
            $params,
            ['quiet' => ParameterType::INTEGER],
        );
        $costs = ($context->may)(ProductPermission::COST_READ) ? $this->unitCosts($context) : null;
        $located = [] === $rows ? [] : $this->tops->filters($context);

        return array_map(static function (array $row) use ($costs, $located): ReportRow {
            $product = self::text($row['product_id']);
            $onHand = self::decimal($row['on_hand']);
            $cells = [
                'product' => self::text($row['name']),
                'reference' => self::text($row['reference']),
                'unit' => self::text($row['unit']),
                'on_hand' => $onHand,
                'last_sold' => null === $row['last_sold'] ? null : self::text($row['last_sold']),
                'quiet_days' => null === $row['quiet_days'] ? null : (int) self::text($row['quiet_days']),
            ];
            if (null !== $costs) {
                $cost = $costs[$product] ?? null;
                $cells['value'] = null === $cost ? null : new Number($onHand)->mul($cost)->round(3)->value;
            }

            // Its movements, the last sale among them; no day bounds them, since the quiet days run up to today.
            return new ReportRow($product, $cells, new ReportDrill('stock-movements', ['product' => $product, ...$located]));
        }, $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        return [];
    }

    /**
     * Each product's average cost as the stock valuation reads it: its value over the quantity valued, none where nothing
     * is valued.
     *
     * @return array<string, numeric-string>
     */
    private function unitCosts(ReportContext $context): array
    {
        $costs = [];
        foreach ($this->stock->valuation($context->company) as $value) {
            $valued = new Number($value->quantity)->sub($value->unvaluedQuantity);
            if (1 === $valued->compare(0)) {
                $costs[$value->productId->toRfc4122()] = new Number($value->value)->div($valued, 10)->value;
            }
        }

        return $costs;
    }

    /** The days without a sale after which the company counts a product as not selling, whether invoices are on or not. */
    private function quietDays(ReportContext $context): int
    {
        $value = $this->settings->value(new SettingContext($context->company), InvoiceWatchSettings::UNSOLD_AFTER_DAYS);

        return \is_int($value) ? $value : throw new \LogicException(\sprintf('%s is not an integer.', InvoiceWatchSettings::UNSOLD_AFTER_DAYS));
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
