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
use Twes\Domain\Money\Exception\InvalidMoneyAmount;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Exception\InvalidCost;
use Twes\Domain\Shared\Decimal;
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
        // REFUSE AT THE EDIT, not at the read. `cost x (1 + rate)` can exceed what a Money can hold even
        // when the cost and the rate are each comfortably inside their own bounds — matching two bounds says
        // nothing about their product. Certification round 5 found `netPrice()` throwing for a
        // one-millime cost with a 100 000 TND price whose cost was later corrected upward: the aggregate
        // constructed, persisted and then 500'd on every read of its price, and because the product page
        // reads it, the record could not be repaired through the UI.
        //
        // This is the mirror of the fix applied to `profitRate()` a round earlier, and the opposite remedy is
        // right here: a *rate* that cannot be derived is legitimately absent, so null is the honest answer —
        // but a *price* always exists, so there is nothing to report and the invalid combination must simply
        // not be constructible.
        // The ROUNDED product, not the exact one — because that is what the `Money` constructor will receive,
        // and rounding can CARRY an extra integer digit. Round 6 found the exact-product version still
        // reachable at the one boundary the guard exists for: a cost of 999999999999000.000 at a rate of
        // 0.0000000001 % has an exact product of 999999999999999.999999999 — fifteen digits, accepted — whose
        // rounded value is 1000000000000000.000, sixteen digits, so `netPrice()` threw for five of the seven
        // rounding modes on an aggregate that had constructed and persisted.
        //
        // `RoundingMode::Up` is the worst case over every mode, which is what makes this sound despite
        // `fromProfitRate()` taking no mode: it rounds furthest from zero, so if the result fits under Up it
        // fits under all seven. Bounding by the worst case is why this method does not need to know which mode
        // a later `netPrice()` call will use.
        $exact = Decimal::multiplyExact($cost->amount(), $profitRate->markupMultiplier());

        // `?? throw`, NOT `?? $exact`. `Decimal::rescale()` returns null for exactly one reason — the caller
        // passed `RoundingMode::Unnecessary` and the value needed rounding — so with `Up` hardcoded above the
        // null arm is unreachable today. Round 11 found it written as `?? $exact`, which is unreachable *and*
        // wrong: falling back to the unrounded exact product is precisely the version round 6 refuted, because
        // rounding can carry an extra integer digit and the guard below would then measure the wrong number.
        // A dead arm that silently produces a wrong value is worse than one that fails loudly, so if a later
        // edit changes that mode this raises instead of quietly re-opening the boundary.
        $product = Decimal::rescale($exact, $cost->currency()->scale(), RoundingMode::Up)
            ?? throw new \LogicException(
                'Decimal::rescale() returned null for RoundingMode::Up, which it does only for '
                . 'RoundingMode::Unnecessary. Do not fall back to the unrounded product here — the digit '
                . 'guard below must measure the value Money will actually receive.',
            );

        if (Decimal::integerDigits($product) > Money::MAX_INTEGER_DIGITS) {
            throw InvalidCost::netPriceWouldNotBeRepresentable($cost, $profitRate, $product);
        }

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
     *
     * **This accessor does not throw ON THE DATA**, and that is a promise rather than an accident: a review
     * found it raising `InvalidRate` for a product that had constructed and persisted perfectly legally (a
     * one-millime cost with a typed price of 1000.000), which is a 500 on a product page rather than a
     * validation error. `PriceCalculator::profitRateFromNet()` reports an unrepresentable derived rate as
     * null, through the same channel as an undefined one, because both mean "no rate to display".
     *
     * The qualifier is not a hedge, and round 11 was right to find it missing: an earlier version of this
     * paragraph said flatly "does not throw", while `RoundingModeIsForwardedTest` pinned — deliberately, in a
     * case named `entryPointsThatMustRefuseAnUnnecessaryRounding` — that a cost of 3.000 with a typed price of
     * 5.000 raises `InvalidMoneyAmount` under `RoundingMode::Unnecessary`. Both are correct and the distinction
     * is the whole point: that mode is the CALLER asserting "this division needs no rounding", and 2/3 needs
     * rounding, so the throw reports a false assertion in the call rather than a defect in the product. Every
     * other mode returns a rate or null. A docblock that promises more than the code delivers is the more
     * expensive artifact, because it is read once and believed.
     *
     * @throws InvalidMoneyAmount if $mode is RoundingMode::Unnecessary and the derivation cannot be exact
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
