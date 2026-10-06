<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use BcMath\Number;
use Symfony\Component\Uid\Uuid;

/**
 * What the stock of one product is worth: its quantity, the value of the part that has a cost, the part that has none,
 * and the part valued by estimate.
 */
final readonly class StockValue
{
    /**
     * @param numeric-string $quantity
     * @param numeric-string $value             seven decimals as summed, rounded by whoever shows it
     * @param numeric-string $unvaluedQuantity  the part with no cost at all
     * @param numeric-string $estimatedQuantity the part with no recorded cost, valued at the product's cost price now
     */
    public function __construct(
        public Uuid $productId,
        public string $quantity,
        public string $value,
        public string $unvaluedQuantity,
        public string $estimatedQuantity = '0.000',
    ) {
    }

    /**
     * The part with no recorded cost valued at the product's cost price as it is now, and said to be an estimate
     * (docs/SPEC.md § 7, C-02). With no cost price there is nothing to estimate at, and it stays unvalued.
     */
    public function estimatedAt(?string $costPrice): self
    {
        $unvalued = new Number($this->unvaluedQuantity);
        if (null === $costPrice || !is_numeric($costPrice) || 0 === $unvalued->compare(0)) {
            return $this;
        }

        return new self(
            $this->productId,
            $this->quantity,
            new Number('0.0000000')->add($this->value)->add($unvalued->mul($costPrice))->value,
            '0.000',
            new Number($this->estimatedQuantity)->add($unvalued)->value,
        );
    }
}
