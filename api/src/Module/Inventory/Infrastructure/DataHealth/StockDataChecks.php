<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Products\Domain\ValueObject\ProductTracking;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What the stock's movements must still say, which the table cannot hold since it cannot see a product's tracking:
 * a tracked product's goods move under one of its own lots and an untracked product's under none, and a serial number
 * is one piece, in stock once or not at all across the company. Read through the movements' mapping to their product,
 * the way the stock reaches a product's tracking everywhere else.
 */
final readonly class StockDataChecks implements DeclaresDataChecks
{
    public const string LOTS = 'inventory.lots';
    public const string SERIALS = 'inventory.serials';

    public function __construct(private EntityManagerInterface $entities)
    {
    }

    public function dataChecks(): array
    {
        return [self::LOTS, self::SERIALS];
    }

    public function findings(string $check): iterable
    {
        return match ($check) {
            self::LOTS => $this->lots(),
            self::SERIALS => $this->serials(),
            default => throw new \LogicException(\sprintf('No stock data check is named %s.', $check)),
        };
    }

    /** @return iterable<DataFinding> */
    private function lots(): iterable
    {
        $rows = $this->entities->createQuery(\sprintf(<<<'DQL'
            SELECT IDENTITY(m.company) AS company, IDENTITY(m.product) AS product, COUNT(m.id) AS movements
            FROM %s m JOIN m.product p LEFT JOIN m.lot l
            WHERE (p.tracking <> :none AND m.lot IS NULL)
               OR (p.tracking = :none AND m.lot IS NOT NULL)
               OR (l.id IS NOT NULL AND l.product <> m.product)
            GROUP BY m.company, m.product
            ORDER BY company, product
            DQL, StockMovement::class))
            ->setParameter('none', ProductTracking::None->value)
            ->getArrayResult();
        foreach ($rows as $found) {
            $row = Rows::row($found);
            yield new DataFinding(self::LOTS, self::uuid($row['company']), \sprintf('%d movements of product %s name a lot its tracking does not give', Rows::int($row['movements']), self::id($row['product'])));
        }
    }

    /** @return iterable<DataFinding> */
    private function serials(): iterable
    {
        $rows = $this->entities->createQuery(\sprintf(<<<'DQL'
            SELECT IDENTITY(m.company) AS company, IDENTITY(m.lot) AS lot, SUM(m.quantity) AS onHand
            FROM %s m JOIN m.product p
            WHERE p.tracking = :serial AND m.lot IS NOT NULL
            GROUP BY m.company, m.lot
            HAVING SUM(m.quantity) > 1 OR SUM(m.quantity) < 0
            ORDER BY company, lot
            DQL, StockMovement::class))
            ->setParameter('serial', ProductTracking::Serial->value)
            ->getArrayResult();
        foreach ($rows as $found) {
            $row = Rows::row($found);
            yield new DataFinding(self::SERIALS, self::uuid($row['company']), \sprintf('serial number %s is in stock %s times', self::id($row['lot']), Rows::text($row['onHand'])));
        }
    }

    private static function uuid(mixed $value): Uuid
    {
        return $value instanceof Uuid ? $value : Uuid::fromString(Rows::text($value));
    }

    private static function id(mixed $value): string
    {
        return self::uuid($value)->toRfc4122();
    }
}
