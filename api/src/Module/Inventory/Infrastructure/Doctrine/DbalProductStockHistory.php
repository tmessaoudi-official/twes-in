<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Products\Application\ProductStockHistory;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Reads the stock_movement table directly, whatever the inventory module's switch says: its data is kept while it is off. */
final readonly class DbalProductStockHistory implements ProductStockHistory
{
    public function __construct(private Connection $connection)
    {
    }

    public function hasMovements(Uuid $productId, Uuid $companyId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM stock_movement WHERE product_id = ? AND company_id = ? LIMIT 1',
            [$productId->toRfc4122(), $companyId->toRfc4122()],
        );
    }
}
