<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Watch;

use App\Module\Inventory\Application\Replay\StockMoveFailures;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\Connection;

/**
 * The documents whose stock move failed after they were kept, until somebody moves it again or says it is settled on the
 * document's page. Oldest first: the longer one waits, the longer the stock is wrong.
 */
final readonly class StockMoveFailureWatch implements DeclaresWatch
{
    public const string MOVE_FAILED = 'stock.move_failed';

    private const string ROWS = 'SELECT f.id, f.document_id, f.move, f.number, f.failed_at::date AS failed_on, f.failed_at
          FROM stock_move_failure f
         WHERE f.company_id = :company';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'stock_moves';
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
        return [self::MOVE_FAILED];
    }

    public function changedBy(): array
    {
        return [StockMoveFailures::KIND];
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
            self::ROWS.' ORDER BY f.failed_at, f.id LIMIT :limit OFFSET :offset',
            ['company' => $company->getId()->toRfc4122(), 'limit' => $request->size, 'offset' => $request->offset()],
        );

        return new Page(array_map(static fn (array $row): WatchItem => new WatchItem(self::MOVE_FAILED, self::text($row['document_id']), [
            'number' => self::text($row['number']),
            'move' => self::text($row['move']),
            'date' => self::text($row['failed_on']),
        ]), $rows), $total, $request);
    }

    private static function known(string $kind): void
    {
        if (self::MOVE_FAILED !== $kind) {
            throw new \LogicException(\sprintf('The stock move watch has no %s.', $kind));
        }
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
