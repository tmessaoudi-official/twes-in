<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain\ValueObject;

use App\Fiscal\Domain\Calculation\Decimal;

/**
 * A price kept while the cost under it moved: the cost the price was set against, the cost now and the price, from which
 * the old margin, the new one and the price that keeps the old one follow.
 */
final readonly class PriceReview
{
    public function __construct(
        public string $previousCost,
        public string $cost,
        public string $price,
    ) {
    }

    /**
     * The price that keeps the margin the product had: the price scaled by the cost's move, worked out in full and rounded
     * once, so a margin read off a rounded rate never drifts it.
     */
    public function suggestedPrice(int $scale): string
    {
        $scaled = Decimal::of($this->price)
            ->mul(Decimal::of($this->cost), Decimal::WORKING_SCALE)
            ->div(Decimal::of($this->previousCost), Decimal::WORKING_SCALE);

        return Decimal::format($scaled, $scale);
    }

    /** The margin the price left over the cost it was set against, as a percentage of the price, to one decimal. */
    public function previousMargin(): string
    {
        return self::marginOn($this->price, $this->previousCost);
    }

    /** The margin the same price leaves over the cost now. */
    public function margin(): string
    {
        return self::marginOn($this->price, $this->cost);
    }

    private static function marginOn(string $price, string $cost): string
    {
        $price = Decimal::of($price);
        $left = $price->sub(Decimal::of($cost))->mul(Decimal::of('100'), Decimal::WORKING_SCALE);

        return Decimal::format($left->div($price, Decimal::WORKING_SCALE), 1);
    }
}
