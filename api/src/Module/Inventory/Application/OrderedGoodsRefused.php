<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\Exception\InvalidStockMovement;

/** A line of goods arriving against an order that the stock refused: which line, by its index, and the stock's own refusal. */
final class OrderedGoodsRefused extends \DomainException
{
    public function __construct(public readonly int $index, public readonly InvalidStockMovement $refusal)
    {
        parent::__construct($refusal->getMessage(), 0, $refusal);
    }
}
