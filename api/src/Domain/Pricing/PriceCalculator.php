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

use Twes\Domain\Money\Money;
use Twes\Domain\Shared\RoundingMode;

/**
 * The profit-rate pricing rules: cost, profit rate and net price as three linked values, plus VAT on
 * top of the net.
 *
 * Two things this class exists to prevent:
 *
 *  1. **VAT computed on the cost instead of the net.** With a cost of 100.000 TND, a 30% profit rate
 *     and 19% VAT, the VAT is 24.700 (19% of 130) and not 19.000 (19% of 100). The base is always the
 *     net.
 *  2. **The three implementations drifting.** The same arithmetic runs in the Angular admin and the
 *     Flutter client so editing feels instant, and the API is the authority that never trusts a
 *     client-supplied trio which does not reconcile. All three are pinned to
 *     `docs/spec/pricing-vectors.json`.
 *
 * **This is the single home of these four formulas.** {@see ProductPricing} decides which field is
 * authoritative and delegates the arithmetic here; nothing else recomputes it. A certification round found
 * the net-from-rate and rate-from-net formulas duplicated across both classes, driven from two different
 * fixture sections with nothing asserting they agreed — the exact drift `CLAUDE.md` § Architecture forbids
 * with "one implementation, never two". If a fifth caller needs one of these, it calls this class.
 *
 * Stateless: it holds no configuration, so the rounding mode is a parameter on every operation rather
 * than a property. Rounding policy belongs to the company that issues the document, and passing it in
 * at the call site is what keeps that configurable without a second code path.
 */
final readonly class PriceCalculator
{
    /**
     * The net selling price: `cost x (1 + profit_rate)`.
     *
     * One multiplication, not `cost + (cost x rate)` — the two-step form rounds twice and can land a
     * millime away from this.
     */
    public function netFromCost(Money $cost, Rate $profitRate, RoundingMode $mode): Money
    {
        return $cost->multipliedBy($profitRate->markupMultiplier(), $mode);
    }

    /**
     * The profit rate implied by a cost and a net price: `(net - cost) / cost`.
     *
     * **Null when the cost is zero**, because the rate is then mathematically undefined rather than
     * zero. A zero would claim the item is sold at cost; an exception would block a legitimate edit.
     * The form shows an empty field, and callers must handle the null rather than coalesce it.
     */
    public function profitRateFromNet(Money $cost, Money $net, RoundingMode $mode): ?Rate
    {
        if ($cost->isZero()) {
            return null;
        }

        return Rate::fromFraction(
            $net->minus($cost)->ratioTo($cost, Rate::FRACTION_SCALE, $mode),
        );
    }

    /**
     * VAT on a net amount. The base is the net — never the cost, and never the gross.
     */
    public function vat(Money $net, Rate $vatRate, RoundingMode $mode): Money
    {
        return $net->multipliedBy($vatRate->fraction(), $mode);
    }

    /**
     * The gross: net plus its VAT.
     *
     * Trivial, and here on purpose — so that "gross" has one definition in the codebase rather than an
     * addition open-coded at each call site, which is how inclusive and exclusive tax handling drifts
     * apart into two implementations.
     */
    public function grossFromNet(Money $net, Money $vat): Money
    {
        return $net->plus($vat);
    }
}
