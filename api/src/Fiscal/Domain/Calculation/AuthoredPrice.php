<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/**
 * A product price that remembers which field the person typed. The typed field is authoritative and never
 * recomputed; only the other one is derived.
 */
final readonly class AuthoredPrice
{
    public const string BY_PROFIT_RATE = 'profit_rate';
    public const string BY_NET_PRICE = 'net_price';

    private function __construct(
        public string $cost,
        public string $netPrice,
        public ?Rate $profitRate,
        public string $authoredBy,
        private int $scale,
    ) {
    }

    public static function byProfitRate(string $cost, Rate $profitRate, int $scale): self
    {
        return new self($cost, ProductPricing::net($cost, $profitRate, $scale), $profitRate, self::BY_PROFIT_RATE, $scale);
    }

    public static function byNetPrice(string $cost, string $netPrice, int $scale): self
    {
        return new self($cost, Decimal::format(Decimal::of($netPrice), $scale), ProductPricing::rate($cost, $netPrice), self::BY_NET_PRICE, $scale);
    }

    /**
     * A new cost keeps the rate and moves the price, so a cost rise never erodes the margin. When the price was the
     * typed field, the rate it implied is what carries forward, and authorship passes to that rate. With no rate
     * (a zero cost) the typed price is kept.
     */
    public function withCost(string $cost): self
    {
        if (null === $this->profitRate) {
            return self::byNetPrice($cost, $this->netPrice, $this->scale);
        }

        return self::byProfitRate($cost, $this->profitRate, $this->scale);
    }
}
