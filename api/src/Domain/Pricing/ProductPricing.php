<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Pricing;

use Twes\Domain\Money\Exception\CurrencyMismatch;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Exception\InvalidCost;
use Twes\Domain\Shared\RoundingMode;

/**
 * A product's cost, its profit rate and its selling price — with a record of which one the user typed.
 *
 * **The rule this class exists to enforce: the value the user typed is never recomputed.** It is stored
 * exactly as entered, and only the *other* value is derived. The derived one is a display convenience and
 * has no authority.
 *
 * Why that matters, with the case that motivated it. Cost 10 000.000 TND, selling price 10 000.001 — one
 * millime of profit. Treat both fields as equally real and you must store a rate, which was rounded to
 * zero at the old six-decimal scale; a later cost change then rebuilt the price from that zero and the
 * millime was gone. Twelve decimals (see {@see Rate::FRACTION_SCALE}) makes the rate precise enough, and
 * this class makes precision unnecessary for the typed field in the first place. Both were asked for, and
 * they are complementary rather than redundant: precision moves the boundary, authorship removes it.
 *
 * **Changing the cost preserves the RATE and moves the PRICE** (developer ruling, 2026-07-29). If the
 * price were held instead, the rate would silently absorb every cost increase and the margin would erode
 * unnoticed — the failure this ordering exists to prevent. When the price is the typed field, the rate
 * implied by the typed pair is what carries forward; at twelve decimals that is exact for any realistic
 * product, and both worked examples above come out to the millime.
 *
 * Immutable. Every change returns a new instance, so an issued document's snapshot can never be moved by
 * a later edit.
 */
final readonly class ProductPricing
{
    /**
     * @throws InvalidCost if the cost is negative
     */
    private function __construct(
        private Money $cost,
        private PricedBy $authoredBy,
        private ?Rate $authoredRate,
        private ?Money $authoredNetPrice,
    ) {
        // In the constructor so EVERY path is covered — both factories, all three `with*` methods.
        // Decided rather than left ambiguous: a certification round found a negative cost accepted and
        // silently producing a negative selling price. See InvalidCost for why this is a pricing rule
        // rather than a Money rule.
        if ($cost->isNegative()) {
            throw InvalidCost::negative($cost);
        }
    }

    /** The user typed a profit rate. It is authoritative and exact; the price is derived from it. */
    public static function fromProfitRate(Money $cost, Rate $profitRate): self
    {
        return new self($cost, PricedBy::ProfitRate, $profitRate, null);
    }

    /**
     * The user typed a selling price. It is authoritative and exact; the rate is derived for display.
     *
     * @throws CurrencyMismatch if the price is not in the cost's currency
     */
    public static function fromNetPrice(Money $cost, Money $netPrice): self
    {
        if (!$cost->currency()->equals($netPrice->currency())) {
            throw CurrencyMismatch::between($cost->currency(), $netPrice->currency());
        }

        return new self($cost, PricedBy::NetPrice, null, $netPrice);
    }

    public function cost(): Money
    {
        return $this->cost;
    }

    public function authoredBy(): PricedBy
    {
        return $this->authoredBy;
    }

    /**
     * The selling price, net of VAT.
     *
     * Exact and unrounded when the user typed it. Derived from cost and rate otherwise, rounding once.
     */
    public function netPrice(RoundingMode $mode): Money
    {
        if (PricedBy::NetPrice === $this->authoredBy) {
            return $this->authoredNetPrice ?? throw new \LogicException('Authored net price is missing.');
        }

        $rate = $this->authoredRate ?? throw new \LogicException('Authored rate is missing.');

        // Delegated, not reimplemented. An earlier version open-coded this and `profitRate()` below, so the
        // same two formulas existed twice in Domain/ with nothing asserting they agreed — against
        // CLAUDE.md § Architecture, "one implementation, never two". This class owns AUTHORSHIP; the
        // arithmetic has exactly one home.
        return new PriceCalculator()->netFromCost($this->cost, $rate, $mode);
    }

    /**
     * The profit rate.
     *
     * Exact when the user typed it. Derived from cost and price otherwise — and **null when the rate has
     * to be DERIVED and the cost is zero**, because `(net - 0) / 0` is undefined rather than zero. A zero
     * would claim the product is sold at cost; the form shows an empty field.
     *
     * Note the precision in that condition. A rate the user *typed* is defined even on a zero cost —
     * nothing is being divided — so it is returned as entered. Only a derived one can be undefined.
     */
    public function profitRate(RoundingMode $mode): ?Rate
    {
        if (PricedBy::ProfitRate === $this->authoredBy) {
            return $this->authoredRate;
        }

        $netPrice = $this->authoredNetPrice ?? throw new \LogicException('Authored net price is missing.');

        // Delegated for the same reason as netPrice() above — including the zero-cost null, which lives in
        // PriceCalculator so both entry points cannot disagree about it.
        return new PriceCalculator()->profitRateFromNet($this->cost, $netPrice, $mode);
    }

    /**
     * The cost changed. The rate is preserved and the price moves.
     *
     * When the price was the typed field, the rate implied by the typed pair becomes the thing carried
     * forward — so authorship transfers to the rate, which is the honest outcome: a price the user typed
     * against the old cost is no longer a statement about the new one, whereas the margin they accepted
     * still is.
     *
     * **Zero costs, both directions.** A zero *old* cost means there is no rate to preserve, so the typed
     * value is kept unchanged rather than invented from nothing. A zero *new* cost on a price-authored
     * product means the typed price must survive the edit — applying any rate to zero would yield zero and
     * silently discard it. Both are guarded, and both have fixture cases; an earlier version guarded only
     * the first, and the fixture only ever moved *away* from a zero cost, so the gap was invisible.
     *
     * @throws CurrencyMismatch if the new cost is in a different currency
     */
    public function withCost(Money $newCost, RoundingMode $mode): self
    {
        if (!$this->cost->currency()->equals($newCost->currency())) {
            throw CurrencyMismatch::between($this->cost->currency(), $newCost->currency());
        }

        // TWO zero checks, and an earlier version had only the first — which deleted the very thing this
        // class exists to protect. Correcting a cost to zero on a price-authored product derived a rate
        // from the OLD pair and then applied it to zero, so the typed price became 0.000. The new cost
        // needs checking as much as the old one.
        if ($newCost->isZero() && PricedBy::NetPrice === $this->authoredBy) {
            return new self($newCost, PricedBy::NetPrice, null, $this->authoredNetPrice);
        }

        $rate = $this->profitRate($mode);

        if (null === $rate) {
            return new self($newCost, $this->authoredBy, $this->authoredRate, $this->authoredNetPrice);
        }

        return self::fromProfitRate($newCost, $rate);
    }

    /** The user typed a new rate. It becomes the authority. */
    public function withProfitRate(Rate $profitRate): self
    {
        return self::fromProfitRate($this->cost, $profitRate);
    }

    /** The user typed a new price. It becomes the authority. */
    public function withNetPrice(Money $netPrice): self
    {
        return self::fromNetPrice($this->cost, $netPrice);
    }
}
