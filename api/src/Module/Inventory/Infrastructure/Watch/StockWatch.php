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
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\Connection;

/**
 * What the stock puts on « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10): a product at or under its reorder
 * point in an establishment, one whose stock at the last 30 days' pace lasts fewer days than it takes to restock, and a
 * dated lot still on hand that expires within the threshold, and one that has expired and nobody released. On-hand is the sum of the
 * movements, as everywhere in the stock.
 */
final readonly class StockWatch implements DeclaresWatch
{
    public const string REORDER_POINT = 'stock.reorder_point';
    public const string RUNNING_OUT = 'stock.running_out';
    public const string LOT_EXPIRING = 'stock.lot_expiring';
    public const string LOT_EXPIRED = 'stock.lot_expired';
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

    public function kinds(): array
    {
        return [self::REORDER_POINT, self::RUNNING_OUT, self::LOT_EXPIRED, self::LOT_EXPIRING];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        [$select, $params] = $this->source($kind, $company, $today);

        return (int) self::text($this->connection->fetchOne('SELECT COUNT(*) FROM ('.$select.') watched', $params));
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $total = $this->count($kind, $company, $today);
        if (0 === $total) {
            return new Page([], 0, $request);
        }
        [$select, $params, $order, $item] = $this->source($kind, $company, $today);
        $rows = $this->connection->fetchAllAssociative(
            $select.' ORDER BY '.$order.' LIMIT :limit OFFSET :offset',
            $params + ['limit' => $request->size, 'offset' => $request->offset()],
        );

        return new Page(array_map($item, $rows), $total, $request);
    }

    /**
     * A kind as its statement: the rows unordered and unpaged, so the count wraps it and the page orders and cuts it; the
     * bindings; the order, which ends in an id; and how a row becomes an item.
     *
     * @return array{string, array<string, mixed>, string, \Closure(array<string, mixed>): WatchItem}
     */
    private function source(string $kind, Company $company, \DateTimeImmutable $today): array
    {
        return match ($kind) {
            self::REORDER_POINT => $this->atReorderPoint($company),
            self::RUNNING_OUT => $this->runningOut($company, $today),
            self::LOT_EXPIRED => $this->lots($company, $today, self::LOT_EXPIRED),
            self::LOT_EXPIRING => $this->lots($company, $today, self::LOT_EXPIRING),
            default => throw new \LogicException(\sprintf('The stock watch has no %s.', $kind)),
        };
    }

    /** @return array{string, array<string, mixed>, string, \Closure(array<string, mixed>): WatchItem} */
    private function atReorderPoint(Company $company): array
    {
        return [
            'SELECT p.id, p.name, p.reference, e.name AS establishment, COALESCE(SUM(m.quantity), 0)::numeric(14, 3) AS on_hand, rp.quantity AS point, rp.establishment_id
               FROM product_reorder_point rp
               JOIN product p ON p.id = rp.product_id
               JOIN establishment e ON e.id = rp.establishment_id
               LEFT JOIN stock_location l ON l.establishment_id = rp.establishment_id
               LEFT JOIN stock_movement m ON m.location_id = l.id AND m.product_id = rp.product_id
              WHERE rp.company_id = :company AND p.is_active
              GROUP BY p.id, p.name, p.reference, e.name, rp.quantity, rp.establishment_id
             HAVING COALESCE(SUM(m.quantity), 0) <= rp.quantity',
            ['company' => $company->getId()->toRfc4122()],
            'p.reference, e.name, p.id, rp.establishment_id',
            static fn (array $row): WatchItem => new WatchItem(self::REORDER_POINT, self::text($row['id']), [
                'product' => self::text($row['name']),
                'reference' => self::text($row['reference']),
                'establishment' => self::text($row['establishment']),
                'onHand' => self::text($row['on_hand']),
                'point' => self::text($row['point']),
            ]),
        ];
    }

    /**
     * Days left is on-hand over the average daily quantity out: a product nothing left in the last 30 days is not
     * running out, and one with nothing left on hand is its reorder point's business.
     *
     * @return array{string, array<string, mixed>, string, \Closure(array<string, mixed>): WatchItem}
     */
    private function runningOut(Company $company, \DateTimeImmutable $today): array
    {
        return [
            'WITH hand AS (
                  SELECT product_id, SUM(quantity) AS on_hand FROM stock_movement WHERE company_id = :company GROUP BY product_id
             ), pace AS (
                  SELECT product_id, -SUM(quantity) AS gone FROM stock_movement
                   WHERE company_id = :company AND kind = :out AND at >= :since GROUP BY product_id
             )
             SELECT p.id, p.name, p.reference, h.on_hand::numeric(14, 3) AS on_hand, FLOOR(h.on_hand * :window / pace.gone)::int AS days_left, h.on_hand * :window / pace.gone AS ratio
               FROM pace JOIN hand h ON h.product_id = pace.product_id JOIN product p ON p.id = pace.product_id
              WHERE p.is_active AND pace.gone > 0 AND h.on_hand > 0 AND h.on_hand * :window < pace.gone * :lead',
            [
                'company' => $company->getId()->toRfc4122(),
                'out' => StockMovementKind::Out->value,
                'since' => $today->modify('-'.self::PACE_DAYS.' days')->format('Y-m-d'),
                'window' => self::PACE_DAYS,
                'lead' => $this->days($company, StockWatchSettings::LEAD_DAYS),
            ],
            'ratio, p.reference, p.id',
            static fn (array $row): WatchItem => new WatchItem(self::RUNNING_OUT, self::text($row['id']), [
                'product' => self::text($row['name']),
                'reference' => self::text($row['reference']),
                'onHand' => self::text($row['on_hand']),
                'days' => (int) self::text($row['days_left']),
            ]),
        ];
    }

    /**
     * A dated lot still on hand, not released: expired when its day has passed, expiring when it falls within the
     * threshold. They are two subjects because they ask for two things, to throw away and to sell first.
     *
     * @param self::LOT_EXPIRED|self::LOT_EXPIRING $kind
     *
     * @return array{string, array<string, mixed>, string, \Closure(array<string, mixed>): WatchItem}
     */
    private function lots(Company $company, \DateTimeImmutable $today, string $kind): array
    {
        $days = $this->days($company, StockWatchSettings::LOT_EXPIRY_DAYS);
        $expired = self::LOT_EXPIRED === $kind;

        return [
            'SELECT p.id, p.name, p.reference, lt.code, lt.expires_on, SUM(m.quantity)::numeric(14, 3) AS quantity
               FROM stock_lot lt
               JOIN product p ON p.id = lt.product_id
               JOIN stock_movement m ON m.lot_id = lt.id
              WHERE lt.company_id = :company AND lt.expires_on IS NOT NULL AND lt.released_at IS NULL
                AND '.($expired ? 'lt.expires_on < :today' : 'lt.expires_on >= :today AND lt.expires_on <= :until').'
              GROUP BY p.id, p.name, p.reference, lt.id, lt.code, lt.expires_on
             HAVING SUM(m.quantity) > 0',
            [
                'company' => $company->getId()->toRfc4122(),
                'today' => $today->format('Y-m-d'),
            ] + ($expired ? [] : ['until' => $today->modify("+$days days")->format('Y-m-d')]),
            'lt.expires_on, p.reference, lt.code, lt.id',
            static function (array $row) use ($today, $kind): WatchItem {
                $expiresOn = new \DateTimeImmutable(self::text($row['expires_on']));

                return new WatchItem($kind, self::text($row['id']), [
                    'product' => self::text($row['name']),
                    'reference' => self::text($row['reference']),
                    'lot' => self::text($row['code']),
                    'expiresOn' => $expiresOn->format('Y-m-d'),
                    'quantity' => self::text($row['quantity']),
                    'days' => (int) $today->diff($expiresOn)->format('%r%a'),
                ]);
            },
        ];
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
