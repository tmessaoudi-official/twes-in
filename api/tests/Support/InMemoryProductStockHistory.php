<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Products\Application\ProductStockHistory;
use Symfony\Component\Uid\Uuid;

final class InMemoryProductStockHistory implements ProductStockHistory
{
    /** @var list<string> ids of the products stock was moved for */
    public array $withMovements = [];

    public function hasMovements(Uuid $productId, Uuid $companyId): bool
    {
        return \in_array($productId->toRfc4122(), $this->withMovements, true);
    }
}
