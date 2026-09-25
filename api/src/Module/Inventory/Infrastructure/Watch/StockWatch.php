<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Watch;

use App\Module\Inventory\Application\StockWatchSettings;
use App\Module\Inventory\Domain\StockMovementKind;
use App\Module\Inventory\Infrastructure\ApiPlatform\StockPermission;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\Connection;

/**
 * What the stock puts on « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10): a product at or under its reorder
 * point in an establishment, one whose stock at the last 30 days' pace lasts fewer days than it takes to restock, and a
 * dated lot still on hand that expires within the threshold (or has, and nobody released it). On-hand is the sum of the
 * movements, as everywhere in the stock.
 */
final readonly class StockWatch implements DeclaresWatch
{
    public const string REORDER_POINT = 'stock.reorder_point';
    public const string RUNNING_OUT = 'stock.running_out';
    public const string LOT_EXPIRING = 'stock.lot_expiring';
    /** The pace is read over the last this many days. */
    private const int PACE_DAYS = 30;

    public function __construct(private Connection $connection, private ReadSetting $settings)
    {
    }

    public function key(): string
    {
        return 'stock';
    }

    public function module(): string
    {
        return InventoryModule::KEY;
    }

    public function permission(): string
    {
        return StockPermission::READ;
    }

    public function itemsFor(Company $company, \DateTimeImmutable $today): array
    {
        return [...$this->atReorderPoint($company), ...$this->runningOut($company, $today), ...$this->lotsExpiring($company, $today)];
    }

    /** @return list<WatchItem> */
    private function atReorderPoint(Company $company): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.name, p.reference, e.name AS establishment, COALESCE(SUM(m.quantity), 0)::numeric(14, 3) AS on_hand, rp.quantity AS point
               FROM product_reorder_point rp
               JOIN product p ON p.id = rp.product_id
               JOIN establishment e ON e.id = rp.establishment_id
               LEFT JOIN stock_location l ON l.establishment_id = rp.establishment_id
               LEFT JOIN stock_movement m ON m.location_id = l.id AND m.product_id = rp.product_id
              WHERE rp.company_id = :company AND p.is_active
              GROUP BY p.id, p.name, p.reference, e.name, rp.quantity
             HAVING COALESCE(SUM(m.quantity), 0) <= rp.quantity
              ORDER BY p.reference, e.name',
            ['company' => $company->getId()->toRfc4122()],
        );

        return array_map(static fn (array $row): WatchItem => new WatchItem(self::REORDER_POINT, self::text($row['id']), [
            'product' => self::text($row['name']),
            'reference' => self::text($row['reference']),
            'establishment' => self::text($row['establishment']),
            'onHand' => self::text($row['on_hand']),
            'point' => self::text($row['point']),
        ]), $rows);
    }

    /**
     * Days left is on-hand over the average daily quantity out: a product nothing left in the last 30 days is not
     * running out, and one with nothing left on hand is its reorder point's business.
     *
     * @return list<WatchItem>
     */
    private function runningOut(Company $company, \DateTimeImmutable $today): array
    {
        $lead = $this->days($company, StockWatchSettings::LEAD_DAYS);
        $rows = $this->connection->fetchAllAssociative(
            'WITH hand AS (
                  SELECT product_id, SUM(quantity) AS on_hand FROM stock_movement WHERE company_id = :company GROUP BY product_id
             ), pace AS (
                  SELECT product_id, -SUM(quantity) AS gone FROM stock_movement
                   WHERE company_id = :company AND kind = :out AND at >= :since GROUP BY product_id
             )
             SELECT p.id, p.name, p.reference, h.on_hand::numeric(14, 3) AS on_hand, FLOOR(h.on_hand * :window / pace.gone)::int AS days_left
               FROM pace JOIN hand h ON h.product_id = pace.product_id JOIN product p ON p.id = pace.product_id
              WHERE p.is_active AND pace.gone > 0 AND h.on_hand > 0 AND h.on_hand * :window < pace.gone * :lead
              ORDER BY h.on_hand * :window / pace.gone, p.reference',
            [
                'company' => $company->getId()->toRfc4122(),
                'out' => StockMovementKind::Out->value,
                'since' => $today->modify('-'.self::PACE_DAYS.' days')->format('Y-m-d'),
                'window' => self::PACE_DAYS,
                'lead' => $lead,
            ],
        );

        return array_map(static fn (array $row): WatchItem => new WatchItem(self::RUNNING_OUT, self::text($row['id']), [
            'product' => self::text($row['name']),
            'reference' => self::text($row['reference']),
            'onHand' => self::text($row['on_hand']),
            'days' => (int) self::text($row['days_left']),
        ]), $rows);
    }

    /** @return list<WatchItem> */
    private function lotsExpiring(Company $company, \DateTimeImmutable $today): array
    {
        $days = $this->days($company, StockWatchSettings::LOT_EXPIRY_DAYS);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.name, p.reference, lt.code, lt.expires_on, SUM(m.quantity)::numeric(14, 3) AS quantity
               FROM stock_lot lt
               JOIN product p ON p.id = lt.product_id
               JOIN stock_movement m ON m.lot_id = lt.id
              WHERE lt.company_id = :company AND lt.expires_on IS NOT NULL AND lt.released_at IS NULL AND lt.expires_on <= :limit
              GROUP BY p.id, p.name, p.reference, lt.id, lt.code, lt.expires_on
             HAVING SUM(m.quantity) > 0
              ORDER BY lt.expires_on, p.reference, lt.code',
            ['company' => $company->getId()->toRfc4122(), 'limit' => $today->modify("+$days days")->format('Y-m-d')],
        );

        return array_map(static function (array $row) use ($today): WatchItem {
            $expiresOn = new \DateTimeImmutable(self::text($row['expires_on']));

            return new WatchItem(self::LOT_EXPIRING, self::text($row['id']), [
                'product' => self::text($row['name']),
                'reference' => self::text($row['reference']),
                'lot' => self::text($row['code']),
                'expiresOn' => $expiresOn->format('Y-m-d'),
                'quantity' => self::text($row['quantity']),
                'days' => (int) $today->diff($expiresOn)->format('%r%a'),
            ]);
        }, $rows);
    }

    private function days(Company $company, string $key): int
    {
        $value = $this->settings->value(new SettingContext($company), $key);

        return \is_int($value) ? $value : throw new \LogicException(\sprintf('%s is not an integer.', $key));
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
