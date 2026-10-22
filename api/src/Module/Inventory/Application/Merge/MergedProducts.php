<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\Merge;

use Symfony\Component\Uid\Uuid;

/**
 * Which products were merged into another, read in the stock store's own transaction under the locks it holds, so a
 * merge carrying the stock away is seen before a movement lands on the product it aliased. A port this module owns,
 * answered by the products'.
 */
interface MergedProducts
{
    /**
     * @param list<Uuid> $productIds
     *
     * @return array<string, string> the reference of the product each one merged away now stands for, by its RFC 4122 id
     */
    public function mergedAway(array $productIds): array;
}
