<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Watch;

use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\Connection;

/**
 * The receipts whose cost somebody who could not read costs left « à compléter », shown to whoever may read costs, who
 * is the one to enter it (docs/SPEC.md § 7, audit 2026-10-06 C challenge 9). Oldest first: the longer a receipt waits,
 * the longer the average carries a guess.
 */
final readonly class ReceiptCostWatch implements DeclaresWatch
{
    public const string COST_TO_COMPLETE = 'stock.receipt_cost_to_complete';

    private const string ROWS = 'SELECT m.id, p.name, p.reference, m.quantity, l.name AS location, COALESCE(m.received_on, m.at::date) AS received_on, m.at
          FROM stock_movement m
          JOIN product p ON p.id = m.product_id
          JOIN stock_location l ON l.id = m.location_id
         WHERE m.company_id = :company AND m.cost_to_complete';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'stock_cost';
    }

    public function module(): string
    {
        return InventoryModule::KEY;
    }

    public function permission(): string
    {
        return ProductPermission::COST_READ;
    }

    public function kinds(): array
    {
        return [self::COST_TO_COMPLETE];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        self::known($kind);

        return (int) self::text($this->connection->fetchOne('SELECT COUNT(*) FROM ('.self::ROWS.') watched', ['company' => $company->getId()->toRfc4122()]));
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $total = $this->count($kind, $company, $today);
        if (0 === $total) {
            return new Page([], 0, $request);
        }
        $rows = $this->connection->fetchAllAssociative(
            self::ROWS.' ORDER BY m.at, m.id LIMIT :limit OFFSET :offset',
            ['company' => $company->getId()->toRfc4122(), 'limit' => $request->size, 'offset' => $request->offset()],
        );

        return new Page(array_map(static fn (array $row): WatchItem => new WatchItem(self::COST_TO_COMPLETE, self::text($row['id']), [
            'product' => self::text($row['name']),
            'reference' => self::text($row['reference']),
            'quantity' => self::text($row['quantity']),
            'location' => self::text($row['location']),
            'receivedOn' => self::text($row['received_on']),
        ]), $rows), $total, $request);
    }

    private static function known(string $kind): void
    {
        if (self::COST_TO_COMPLETE !== $kind) {
            throw new \LogicException(\sprintf('The receipt cost watch has no %s.', $kind));
        }
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
