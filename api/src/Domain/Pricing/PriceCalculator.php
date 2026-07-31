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
     *
     * @throws InvalidMoneyAmount if \$mode is RoundingMode::Unnecessary and the product needs rounding
     */
    public function netFromCost(Money $cost, Rate $profitRate, RoundingMode $mode): Money
    {
        return $cost->multipliedBy($profitRate->markupMultiplier(), $mode);
    }

    /**
     * The profit rate implied by a cost and a net price: `(net - cost) / cost`.
     *
     * **Null in two cases, both meaning "there is no rate to show", never "the rate is zero":**
     *
     *  - **The cost is zero**, so the rate is mathematically undefined. A zero would claim the item is sold
     *    at cost; an exception would block a legitimate edit. The form shows an empty field.
     *  - **The result is too large to be a `Rate`.** Only reachable from an absurd pair now that the bound
     *    matches `Money`'s own, but returning null keeps the promise that matters: this is called from
     *    `ProductPricing::profitRate()`, a read accessor, and a getter must not throw on state that was
     *    persisted legally. A review found exactly that — a 1-millime cost with a typed price of 1000.000
     *    constructed fine and then threw on every read.
     *
     * Callers must handle the null rather than coalesce it; `??  Rate::zero()` is the bug this guards.
     */
    public function profitRateFromNet(Money $cost, Money $net, RoundingMode $mode): ?Rate
    {
        if ($cost->isZero()) {
            return null;
        }

        $fraction = $net->minus($cost)->ratioTo($cost, Rate::FRACTION_SCALE, $mode);

        // Asked, not caught. Catching InvalidRate here would also swallow a malformed or over-precise
        // value, which are programming errors in this class rather than conditions to report as "no rate".
        if (!Rate::canHoldFraction($fraction)) {
            return null;
        }

        return Rate::fromFraction($fraction);
    }

    /**
     * VAT on a net amount. The base is the net — never the cost, and never the gross.
     *
     * @throws InvalidMoneyAmount if \$mode is RoundingMode::Unnecessary and the VAT needs rounding
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
     *
     * @throws CurrencyMismatch if the VAT is not in the net's currency
     * @throws InvalidMoneyAmount if the sum is not representable
     */
    public function grossFromNet(Money $net, Money $vat): Money
    {
        return $net->plus($vat);
    }
}
