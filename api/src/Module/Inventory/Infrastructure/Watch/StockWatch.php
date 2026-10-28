<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Watch;

use App\Module\Inventory\Application\StockWatchSettings;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\ValueObject\StockLocationKind;
use App\Module\Inventory\Domain\ValueObject\StockMovementKind;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Module\Inventory\Infrastructure\Doctrine\StockKept;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Infrastructure\Doctrine\KeptRecords;
use App\Tenancy\Domain\Model\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\Connection;

/**
 * What the stock puts on « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10): a product below zero at a place, which a
 * sale without stock leaves and a count settles, a product at or under its reorder
 * point in an establishment, one whose stock at the last 30 days' pace lasts fewer days than it takes to restock, and a
 * dated lot still on hand that expires within the threshold, and one that has expired and nobody released. On-hand is the sum of the
 * movements, as everywhere in the stock; for the reorder point and the days left it leaves out the locations in quarantine, whose
 * goods cannot be sold, while a lot is watched wherever it waits, since an expired one there still has to be thrown away.
 */
final readonly class StockWatch implements DeclaresWatch
{
    public const string BELOW_ZERO = 'stock.below_zero';
    public const string REORDER_POINT = 'stock.reorder_point';
    public const string RUNNING_OUT = 'stock.running_out';
    public const string LOT_EXPIRING = 'stock.lot_expiring';
    public const string LOT_EXPIRED = 'stock.lot_expired';
    /** The unit a row's quantity is in, as the screens name it, of the product `p`: a quantity is said with its unit. */
    private const string UNIT = '(SELECT u.name FROM unit u WHERE u.id = p.unit_id) AS unit';
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
        return [self::BELOW_ZERO, self::REORDER_POINT, self::RUNNING_OUT, self::LOT_EXPIRED, self::LOT_EXPIRING];
    }

    public function changedBy(): array
    {
        return ['stock', 'stock_location', 'product', 'product_reorder_point', 'setting', 'delivery_note', 'invoice', 'purchase_order'];
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
            self::BELOW_ZERO => $this->belowZero($company),
            self::REORDER_POINT => $this->atReorderPoint($company),
            self::RUNNING_OUT => $this->runningOut($company, $today),
            self::LOT_EXPIRED => $this->lots($company, $today, self::LOT_EXPIRED),
            self::LOT_EXPIRING => $this->lots($company, $today, self::LOT_EXPIRING),
            default => throw new \LogicException(\sprintf('The stock watch has no %s.', $kind)),
        };
    }

    /**
     * What a sale took below zero, reorder point or not (Decisions Log 2026-10-28 00:13): a sale is never blocked, so a
     * place short of what left it is how a missing count shows. The rows are the stock tab's own « Sous zéro », its level
     * per product, place and lot below zero, of the products whose stock is kept: a count of any other is refused, so
     * its row could never leave the list, while the stock tab still shows its level as it was left. A product switched
     * off is kept, its stock being as wrong as any other's. A place is what « Compter » counts, which is why the row
     * names it and not only its establishment.
     *
     * @return array{string, array<string, mixed>, string, \Closure(array<string, mixed>): WatchItem}
     */
    private function belowZero(Company $company): array
    {
        return [
            'SELECT p.id, p.name, p.reference, e.name AS establishment, l.id AS location_id, l.code AS location_code, l.name AS location, lt.code AS lot, SUM(m.quantity)::numeric(14, 3) AS on_hand, '.self::UNIT.'
               FROM stock_movement m
               JOIN product p ON p.id = m.product_id
               JOIN stock_location l ON l.id = m.location_id
               JOIN establishment e ON e.id = l.establishment_id
               LEFT JOIN stock_lot lt ON lt.id = m.lot_id
              WHERE m.company_id = :company AND '.StockKept::sql('p.kind', 'p.company_id', 'p.category_id', 'p.id', ':company_keeps').'
              GROUP BY p.id, p.name, p.reference, e.name, l.id, l.code, l.name, lt.id, lt.code
             HAVING SUM(m.quantity) < 0',
            ['company' => $company->getId()->toRfc4122(), 'company_keeps' => StockKept::byCompany($this->settings, $company) ? 'true' : 'false'],
            'on_hand, p.reference, l.code, p.id, l.id, lt.id NULLS FIRST',
            static fn (array $row): WatchItem => new WatchItem(self::BELOW_ZERO, self::text($row['id']), [
                'product' => self::text($row['name']),
                'reference' => self::text($row['reference']),
                'establishment' => self::text($row['establishment']),
                'location' => self::text($row['location_code']).' — '.self::text($row['location']),
                'locationId' => self::text($row['location_id']),
                'onHand' => self::text($row['on_hand']),
                'unit' => self::text($row['unit']),
            ] + (null === $row['lot'] ? [] : ['lot' => self::text($row['lot'])])),
        ];
    }

    /** @return array{string, array<string, mixed>, string, \Closure(array<string, mixed>): WatchItem} */
    private function atReorderPoint(Company $company): array
    {
        return [
            'SELECT p.id, p.name, p.reference, e.name AS establishment, COALESCE(SUM(m.quantity), 0)::numeric(14, 3) AS on_hand, rp.quantity AS point, rp.establishment_id, '.self::UNIT.'
               FROM product_reorder_point rp
               JOIN product p ON p.id = rp.product_id
               JOIN establishment e ON e.id = rp.establishment_id
               LEFT JOIN stock_location l ON l.establishment_id = rp.establishment_id AND l.kind <> :quarantine
               LEFT JOIN stock_movement m ON m.location_id = l.id AND m.product_id = rp.product_id
              WHERE rp.company_id = :company AND p.is_active
              GROUP BY p.id, p.name, p.reference, e.name, rp.quantity, rp.establishment_id
             HAVING COALESCE(SUM(m.quantity), 0) <= rp.quantity',
            ['company' => $company->getId()->toRfc4122(), 'quarantine' => StockLocationKind::Quarantine->value],
            'p.reference, e.name, p.id, rp.establishment_id',
            static fn (array $row): WatchItem => new WatchItem(self::REORDER_POINT, self::text($row['id']), [
                'product' => self::text($row['name']),
                'reference' => self::text($row['reference']),
                'establishment' => self::text($row['establishment']),
                'onHand' => self::text($row['on_hand']),
                'point' => self::text($row['point']),
                'unit' => self::text($row['unit']),
            ]),
        ];
    }

    /**
     * Days left is on-hand over the average daily quantity out, a move's or a merge's half that leaves being no quantity
     * gone; what a product merged away sold and holds is read under the product kept, as the reports read it: a
     * product nothing left in the last 30 days is not
     * running out, and one with nothing left on hand is its reorder point's business.
     *
     * @return array{string, array<string, mixed>, string, \Closure(array<string, mixed>): WatchItem}
     */
    private function runningOut(Company $company, \DateTimeImmutable $today): array
    {
        return [
            'WITH hand AS (
                  SELECT '.KeptRecords::id('mp').' AS product_id, SUM(m.quantity) AS on_hand
                    FROM stock_movement m JOIN stock_location l ON l.id = m.location_id JOIN product mp ON mp.id = m.product_id
                   WHERE m.company_id = :company AND l.kind <> :quarantine GROUP BY '.KeptRecords::id('mp').'
             ), pace AS (
                  SELECT '.KeptRecords::id('mp').' AS product_id, -SUM(m.quantity) AS gone
                    FROM stock_movement m JOIN product mp ON mp.id = m.product_id
                   WHERE m.company_id = :company AND m.kind = :out AND m.source_type NOT IN (:move, :merge, :import_undo, :import_redo) AND m.at >= :since
                   GROUP BY '.KeptRecords::id('mp').'
             )
             SELECT p.id, p.name, p.reference, h.on_hand::numeric(14, 3) AS on_hand, FLOOR(h.on_hand * :window / pace.gone)::int AS days_left, h.on_hand * :window / pace.gone AS ratio, '.self::UNIT.'
               FROM pace JOIN hand h ON h.product_id = pace.product_id JOIN product p ON p.id = pace.product_id
              WHERE p.is_active AND pace.gone > 0 AND h.on_hand > 0 AND h.on_hand * :window < pace.gone * :lead',
            [
                'company' => $company->getId()->toRfc4122(),
                'out' => StockMovementKind::Out->value,
                'quarantine' => StockLocationKind::Quarantine->value,
                'move' => StockMovement::SOURCE_MOVE,
                'merge' => StockMovement::SOURCE_MERGE,
                'import_undo' => StockMovement::SOURCE_IMPORT_UNDO,
                'import_redo' => StockMovement::SOURCE_IMPORT_REDO,
                'since' => $today->modify('-'.self::PACE_DAYS.' days')->format('Y-m-d'),
                'window' => self::PACE_DAYS,
                'lead' => $this->days($company, StockWatchSettings::LEAD_DAYS),
            ],
            'ratio, p.reference, p.id',
            static fn (array $row): WatchItem => new WatchItem(self::RUNNING_OUT, self::text($row['id']), [
                'product' => self::text($row['name']),
                'reference' => self::text($row['reference']),
                'onHand' => self::text($row['on_hand']),
                'unit' => self::text($row['unit']),
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
            'SELECT p.id, p.name, p.reference, lt.code, lt.expires_on, SUM(m.quantity)::numeric(14, 3) AS quantity, '.self::UNIT.'
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
                    'unit' => self::text($row['unit']),
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
