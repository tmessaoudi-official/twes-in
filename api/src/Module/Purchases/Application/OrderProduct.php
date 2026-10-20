<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use Symfony\Component\Uid\Uuid;

/**
 * A product as an order line needs it: its reference and name, the unit it is counted in and that unit's name, whether it is goods (only
 * goods are ordered here), whether a new line may still name it, how it is tracked (none, lot or serial: whether a
 * receipt names the lot), and what it costs today, the price a new line starts from for whoever may read costs.
 */
final readonly class OrderProduct
{
    public function __construct(
        public Uuid $id,
        public string $reference,
        public string $name,
        public Uuid $unitId,
        public string $unitName,
        public bool $goods,
        public bool $active,
        public string $tracking,
        public ?string $costPrice,
        /** The reference of the product kept, when this one was merged into another. */
        public ?string $keptReference = null,
    ) {
    }
}
