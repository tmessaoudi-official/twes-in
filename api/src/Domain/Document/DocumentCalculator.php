<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Document;

use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Exception\CurrencyMismatch;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\PriceCalculator;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\Decimal;
use Twes\Domain\Shared\RoundingMode;

/**
 * The calculation kernel: line nets, the VAT breakdown, fixed charges, and the document total.
 *
 * **ONE implementation, parameterised** — the rule CLAUDE.md § Architecture states and this class exists to
 * obey. The rounding point is a {@see VatRoundingPoint} argument, not a subclass. Upstream maintains
 * `InvoiceSum`/`InvoiceSumInclusive` and `InvoiceItemSum`/`InvoiceItemSumInclusive` as four classes that have
 * to be kept numerically in step by hand, and that duplication is a large part of why their test suite is
 * 167k LOC. Two code paths for one formula do not stay equal; they drift, and the drift is a wrong number on
 * a legal document rather than a crash.
 *
 * **The rounding ORDER is the whole design.** VAT is grouped by rate, each group's bases summed, and each
 * group's VAT rounded ONCE — ruled in `pricing-and-documents.plan.md`, and the reason is external rather
 * than aesthetic: it is what an EN 16931 / Peppol validator recomputes, so any other order produces a payload
 * that disagrees with the receiving system and is rejected. The shared vectors pin the divergence at a
 * millime (0.005 versus 0.004 on two lines of 0.013 TND at 19%), which is exactly the size of error that
 * survives review and then fails an audit.
 *
 * **Nothing here reimplements arithmetic.** The VAT of a base is `PriceCalculator::vat()`, addition is
 * `Money::plus()`, and both refuse to mix currencies. This class owns *composition and order* — which
 * figures are summed, and when rounding happens — and nothing else.
 *
 * Framework-free and I/O-free, like the rest of `Domain/`: no clock, no randomness, no environment. Every
 * input arrives as an argument, which is what makes the ordering above testable against a committed fixture
 * rather than against a running system.
 */
final readonly class DocumentCalculator
{
    /**
     * @param list<DocumentLine> $lines
     * @param list<FixedCharge> $fixedCharges
     * @param Currency|null $currency required only when there is nothing to infer it from — an empty
     *                                document. Inferred from the first line otherwise, and every other
     *                                line and charge must agree
     *
     * @throws CurrencyMismatch if any line or charge is in a different currency
     * @throws \InvalidArgumentException if the document is empty and no currency was given
     */
    public function calculate(
        array $lines,
        array $fixedCharges,
        VatRoundingPoint $vatRoundingPoint,
        RoundingMode $mode,
        ?Currency $currency = null,
    ): DocumentTotals {
        $currency = $this->resolveCurrency($lines, $fixedCharges, $currency);
        $zero = Money::zero($currency);
        $prices = new PriceCalculator();

        $lineNets = [];
        $lineVatByIndex = [];
        // Keyed by the rate's canonical percentage string, which is what makes two lines "the same rate":
        // Rate canonicalises to a fixed scale, so `19`, `19.0` and `19.0000000000` are one group rather than
        // three. Grouping on the object would make them three, and each would round separately — silently
        // reintroducing per-line rounding while the caller asked for per-rate-group.
        $bases = [];
        $perLineVat = [];
        $rates = [];
        $linesInGroup = [];

        foreach ($lines as $line) {
            $net = $line->net($mode);
            $lineNets[] = $net;

            $key = $line->vatRate()->percentage();
            $rates[$key] ??= $line->vatRate();
            // Which LINES belong to this rate group, in document order — needed to allocate the group's VAT back
            // across them. Indexes rather than the lines themselves, so the allocation can address `$lineNets`.
            $linesInGroup[$key][] = \count($lineNets) - 1;
            $bases[$key] = isset($bases[$key]) ? $bases[$key]->plus($net) : $net;

            // Computed on every path, not only when PerLine is asked for. The alternative — branching here —
            // means the two arms exercise different code and the cheap one rots; this way the only difference
            // between the modes is WHICH already-computed figure is summed, which is the smallest honest
            // difference the parameter can have.
            $lineVat = $prices->vat($net, $line->vatRate(), $mode);
            $perLineVat[$key] = isset($perLineVat[$key]) ? $perLineVat[$key]->plus($lineVat) : $lineVat;
            // KEPT PER LINE as well as summed per group, because under PerLine this figure IS the line's
            // share and must not be re-derived by allocation — see the `PerLine` arm below.
            $lineVatByIndex[] = $lineVat;
        }

        $vatByRate = [];
        $vatTotal = $zero;
        $vatByLine = [];

        foreach ($bases as $key => $base) {
            // THE ROUNDING POINT. PerRateGroup rounds the summed base once; PerLine sums figures that were
            // each already rounded. One `match`, both arms reached by the shared vectors.
            $groupVat = match ($vatRoundingPoint) {
                VatRoundingPoint::PerRateGroup => $prices->vat($base, $rates[$key], $mode),
                VatRoundingPoint::PerLine => $perLineVat[$key],
            };

            $vatByRate[] = new VatGroup($rates[$key], $base, $groupVat);
            $vatTotal = $vatTotal->plus($groupVat);

            // ALLOCATE the group's VAT back across its own lines — developer ruling, 2026-07-31: a per-line VAT
            // figure is required. It cannot simply be `lineNet × rate` rounded: under PerRateGroup the group's VAT
            // is rounded ONCE on the summed base, so the rounded per-line figures do not add up to it. Two 0.013
            // TND lines at 19% give a group VAT of 0.005 while each line's own rounded VAT is 0.002 — 0.004, a
            // millime short. A document whose line column does not sum to its VAT total is a document a tax
            // authority reads as arithmetically wrong.
            // **AND ONLY UNDER PerRateGroup.** Under PerLine the group's VAT is BY CONSTRUCTION the sum of the
            // per-line rounded figures computed above, so those figures already add up to it exactly and there
            // is nothing to allocate. Allocating anyway moved a millime away from the line that actually owed
            // it — round 17's F1: `allocate()` floors with `RoundingMode::Down` regardless of the caller's
            // mode, so with HalfEven two lines of `0.150` and `0.050 TND` at 19% reported `[0.029, 0.009]`
            // where each line's own rounded VAT is `[0.028, 0.010]`, and the answer changed when the two lines
            // were swapped. Order-dependence in a tax figure is exactly what largest-remainder exists to
            // prevent, so the bug was in applying it where it does not belong rather than in the rule.
            $shares = match ($vatRoundingPoint) {
                VatRoundingPoint::PerRateGroup
                    => self::allocate($groupVat, $linesInGroup[$key], $lineNets, $rates[$key]),
                VatRoundingPoint::PerLine
                    => self::sharesAsRounded($linesInGroup[$key], $lineVatByIndex),
            };

            foreach ($shares as $i => $share) {
                $vatByLine[$i] = $share;
            }
        }

        $subtotalNet = $zero;

        foreach ($lineNets as $net) {
            $subtotalNet = $subtotalNet->plus($net);
        }

        $fixedChargesTotal = $zero;

        foreach ($fixedCharges as $charge) {
            $fixedChargesTotal = $fixedChargesTotal->plus($charge->amount());
        }

        // The total is net + VAT + fixed charges, and the ORDER of that sum is irrelevant because every term
        // is already exact at the currency's scale — `Money::plus()` is exact by construction and throws
        // rather than rounding. That is why no rounding mode appears in this line.
        //
        // The fixed charges are added HERE and were never added to a base above. Taxing a stamp duty is a
        // silent overcharge on every invoice, so it is excluded by construction rather than by a filter
        // somebody has to remember.
        $total = $subtotalNet->plus($vatTotal)->plus($fixedChargesTotal);

        ksort($vatByLine);

        return new DocumentTotals(
            $lineNets,
            $subtotalNet,
            $vatByRate,
            array_values($vatByLine),
            $vatTotal,
            $fixedChargesTotal,
            $total,
        );
    }

    /**
     * Split a rate group's VAT across its own lines by **LARGEST REMAINDER**, ties to the earliest line.
     *
     * Developer ruling, 2026-07-31: a per-line VAT figure is required. That makes an allocation rule unavoidable,
     * because under `PerRateGroup` the group's VAT is rounded ONCE on the summed base and the rounded per-line
     * figures therefore do not add up to it — two `0.013 TND` lines at 19% give a group VAT of `0.005` while each
     * line's own rounded VAT is `0.002`, leaving a millime nobody owns.
     *
     * **The invariant this method exists to hold is that the allocated shares sum EXACTLY to the group VAT**, for
     * every input. A line column that does not add up to the VAT total is a document a tax authority reads as
     * arithmetically wrong, and it is the only reason to allocate rather than to recompute per line.
     *
     * Largest remainder rather than "put the difference on the last line": that alternative makes DOCUMENT ORDER
     * significant to a tax figure, so re-ordering two lines would change which line is taxed what — a defect in a
     * legal document, and one no reviewer would see because the total stays right. Largest remainder gives the
     * millime to the line with the strongest claim to it, and the earliest-line tie-break makes the result
     * deterministic, which the cross-tier vectors need in order to pin anything at all.
     *
     * The remainder is distributed in whole units of the currency's smallest unit, so the sum is exact by
     * construction rather than by a final correction — there is no "and then fix the last one" step to get wrong.
     *
     * @param list<int> $lineIndexes the positions, in document order, of the lines carrying this rate
     * @param list<Money> $lineNets every line's net, indexed by position
     *
     * @return array<int, Money> share per line position
     */
    private static function allocate(
        Money $groupVat,
        array $lineIndexes,
        array $lineNets,
        Rate $rate,
    ): array {
        $scale = $groupVat->currency()->scale();
        $shares = [];
        $allocated = Money::zero($groupVat->currency());

        // FLOOR each line's exact share first, so the running sum can only ever be short — never over. Rounding
        // to nearest here would allow the sum to EXCEED the group VAT, and there is no way to take a millime back
        // from a line without choosing a victim, which is the arbitrariness this method exists to avoid.
        //
        // **`Floor`, NOT `Down`** (round 17's F3). `Down` truncates TOWARD ZERO, which is not a floor for a
        // negative amount: two lines of an exact `-0.0025` truncate to `-0.002` each, so the running sum
        // OVERSHOOTS a group VAT of `-0.005` and the shortfall goes negative — the invariant this method exists
        // to hold, broken, and silently absorbed by a `max(0, …)` clamp that used to sit below. Unreachable
        // today because `DocumentLine` refuses a negative quantity, unit price and rate, but Wave 2's credit
        // note is named in this very file and a clamp with no stated failure mode is what CLAUDE.md's
        // anti-bandaid gate forbids. `Floor` makes the algorithm correct for either sign instead of guarding
        // one, and it is what keeps `$units` non-negative below: `floor(a) + floor(b) <= floor(a + b)` holds for
        // negatives too, while truncation toward zero does not.
        $remainders = [];

        foreach ($lineIndexes as $i) {
            $exact = Decimal::multiplyExact($lineNets[$i]->amount(), $rate->fraction());
            $floor = Decimal::rescale($exact, $scale, RoundingMode::Floor)
                ?? throw new \LogicException('RoundingMode::Floor cannot need rounding.');

            $shares[$i] = Money::of($floor, $groupVat->currency());
            $allocated = $allocated->plus($shares[$i]);
            // What this line gave up when floored. The comparison below is on the exact remainder, not on a
            // rounded one, so two lines are tied only when they are genuinely equally deserving.
            $remainders[$i] = Decimal::subtract($exact, $floor, Decimal::scaleOf($exact));
        }

        // The currency's SMALLEST UNIT as a decimal string: `1` at scale 0, `0.001` at TND's scale 3. Built here
        // rather than taken from `Money`, because `Money` has no notion of a minor unit and must not acquire one —
        // CLAUDE.md forbids "cents" in a name precisely because the default currency has three decimals.
        $unit = 0 === $scale ? '1' : '0.' . str_repeat('0', $scale - 1) . '1';

        // How many of those units are still unallocated. Exact division, since the shortfall is a whole number of
        // units by construction: both operands are at the currency's scale.
        $shortfall = Decimal::subtract($groupVat->amount(), $allocated->amount(), $scale);
        // `?? throw` rather than `?? '0'` (round 17's F8): `Decimal::divide()` returns null only under
        // `RoundingMode::Unnecessary` and this call passes `Down`, so the fallback was unreachable — and a
        // fallback with no failure mode silently substitutes "allocate nothing" for a broken invariant. This is
        // the form `DocumentLine` already uses two files over, for the reason stated there.
        $units = (int) (Decimal::divide($shortfall, $unit, 0, RoundingMode::Down)
            ?? throw new \LogicException('An exact division at scale 0 cannot need rounding.'));

        // NON-NEGATIVE BY CONSTRUCTION, asserted rather than clamped. Every rounding mode returns at least
        // `floor(exact)`, and flooring is superadditive, so the group VAT can never be less than the sum of the
        // floored shares. The old `max(0, $units)` turned a violation of that into a silently wrong column.
        if ($units < 0) {
            throw new \LogicException(\sprintf(
                'The floored shares (%s) exceed the group VAT (%s), which flooring makes impossible. '
                . 'Allocating on would under-report the tax on some line.',
                $allocated->amount(),
                $groupVat->amount(),
            ));
        }

        // Order by remainder DESC, then by position ASC. `uasort` is stable in PHP 8, but the position tie-break is
        // written explicitly rather than relied upon: "stable sort" is an implementation guarantee and this is a
        // tax figure.
        $order = $lineIndexes;
        usort($order, static fn(int $a, int $b): int
            => Decimal::compare($remainders[$b], $remainders[$a]) ?: $a <=> $b);

        foreach (\array_slice($order, 0, $units) as $i) {
            $shares[$i] = $shares[$i]->plus(Money::of($unit, $groupVat->currency()));
        }

        return $shares;
    }

    /**
     * Each line's share under `PerLine`: the line's OWN already-rounded VAT, unaltered.
     *
     * Not an allocation, and deliberately not routed through {@see allocate()}. Under `PerLine` the group's VAT
     * is defined as the sum of exactly these figures, so they add up to it by construction — there is no
     * remainder to distribute and any redistribution is therefore a wrong number, not a rounding choice. Round
     * 17 found `allocate()` being applied here and moving a millime between two lines, which also made the
     * result depend on line order.
     *
     * @param list<int> $lineIndexes the positions, in document order, of the lines carrying this rate
     * @param list<Money> $lineVat every line's own rounded VAT, indexed by position
     *
     * @return array<int, Money> share per line position
     */
    private static function sharesAsRounded(array $lineIndexes, array $lineVat): array
    {
        $shares = [];

        foreach ($lineIndexes as $i) {
            $shares[$i] = $lineVat[$i];
        }

        return $shares;
    }

    /**
     * The document's single currency, inferred where possible and asserted everywhere.
     *
     * A document is single-currency by definition. `Money` would refuse the mixed arithmetic anyway, but it
     * would refuse it partway through a sum with a message about two amounts — this fails before any figure
     * is computed, so the error names the document rather than an intermediate value.
     *
     * **The two `list<>` generics are load-bearing and were lost once** (round 17's P1-2): this docblock was
     * orphaned onto `allocate()` — which throws no `CurrencyMismatch` — leaving this method with no annotation
     * at all, in the commit whose message claimed it had fixed two orphaned docblocks. PHPStan at max level
     * enforces these and would have caught it; PHPStan is blocked on Composer egress, so nothing did.
     *
     * @param list<DocumentLine> $lines
     * @param list<FixedCharge> $fixedCharges
     *
     * @throws CurrencyMismatch
     * @throws \InvalidArgumentException
     */
    private function resolveCurrency(array $lines, array $fixedCharges, ?Currency $currency): Currency
    {
        // NAMED POSITIONS, because `Money::plus()` raises the same exception CLASS a few lines downstream and
        // round 14 found both of these guards deletable with the suite green — the test asserted only the class,
        // so a crash and a detection were indistinguishable. The context string is what a test can pin, and it
        // is also what tells a reader WHICH line of a fifty-line invoice is wrong.
        // `array_values` FIRST, so the reported position is the position, not the array key. `list<DocumentLine>`
        // is a docblock claim and PHPStan — which would enforce it — is blocked on Composer egress, so a caller
        // passing `[7 => $line]` made the message name line 7 while `DocumentTotals::lineNets()` exposes 0 and 1.
        // An index a client is told to fix must be the index the client can see (round 15).
        $lines = array_values($lines);
        $fixedCharges = array_values($fixedCharges);

        foreach ($lines as $position => $line) {
            $currency ??= $line->unitNet()->currency();

            if (!$currency->equals($line->unitNet()->currency())) {
                throw CurrencyMismatch::inContext(
                    $currency,
                    $line->unitNet()->currency(),
                    \sprintf('document line %d', $position),
                );
            }
        }

        foreach ($fixedCharges as $position => $charge) {
            $currency ??= $charge->amount()->currency();

            if (!$currency->equals($charge->amount()->currency())) {
                throw CurrencyMismatch::inContext(
                    $currency,
                    $charge->amount()->currency(),
                    \sprintf('document charge %d', $position),
                );
            }
        }

        // An EMPTY document has nothing to infer from, and guessing would be the worst option available: a
        // default of TND would make a EUR company's new invoice silently three-decimal. So the caller states
        // it, and forgetting to is an error rather than a default.
        return $currency ?? throw new \InvalidArgumentException(
            'A document with no lines and no charges has no currency to infer. Pass the document\'s '
            . 'currency explicitly — defaulting one would make an empty invoice silently adopt the wrong '
            . 'scale, and TND has three decimals where EUR has two.',
        );
    }
}
