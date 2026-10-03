<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\CostOnReceive;

/**
 * What a receipt form shows about a product's cost: the mode the company chose, the cost now, the average as the
 * receipt would leave it and the latest cost somebody typed on a receipt.
 */
final readonly class ReceiptCost
{
    /**
     * @param numeric-string|null $costNow  four decimals
     * @param numeric-string|null $average  four decimals
     * @param numeric-string|null $lastCost as typed
     */
    public function __construct(
        public CostOnReceive $mode,
        public ?string $costNow,
        public ?string $average,
        public ?string $lastCost,
        public ?\DateTimeImmutable $lastAt,
    ) {
    }
}
