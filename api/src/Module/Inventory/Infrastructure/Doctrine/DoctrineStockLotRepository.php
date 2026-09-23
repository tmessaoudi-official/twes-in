<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Inventory\Domain\StockLot;
use App\Module\Inventory\Domain\StockLotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineStockLotRepository implements StockLotRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(StockLot $lot): void
    {
        $this->entityManager->persist($lot);
        $this->entityManager->flush();
    }

    public function ofCode(Uuid $productId, string $code): ?StockLot
    {
        return $this->entityManager->getRepository(StockLot::class)->findOneBy(['product' => $productId, 'code' => $code]);
    }

    /** A transaction-scoped advisory lock, as for stock: the lot's row may not exist yet, so there is no row to lock. */
    public function lock(Uuid $productId, string $code): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['lot:'.$productId->toRfc4122().':'.$code],
        );
    }
}
