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
        // Keyed by the rate's canonical percentage string, which is what makes two lines "the same rate":
        // Rate canonicalises to a fixed scale, so `19`, `19.0` and `19.0000000000` are one group rather than
        // three. Grouping on the object would make them three, and each would round separately — silently
        // reintroducing per-line rounding while the caller asked for per-rate-group.
        $bases = [];
        $perLineVat = [];
        $rates = [];

        foreach ($lines as $line) {
            $net = $line->net($mode);
            $lineNets[] = $net;

            $key = $line->vatRate()->percentage();
            $rates[$key] ??= $line->vatRate();
            $bases[$key] = isset($bases[$key]) ? $bases[$key]->plus($net) : $net;

            // Computed on every path, not only when PerLine is asked for. The alternative — branching here —
            // means the two arms exercise different code and the cheap one rots; this way the only difference
            // between the modes is WHICH already-computed figure is summed, which is the smallest honest
            // difference the parameter can have.
            $lineVat = $prices->vat($net, $line->vatRate(), $mode);
            $perLineVat[$key] = isset($perLineVat[$key]) ? $perLineVat[$key]->plus($lineVat) : $lineVat;
        }

        $vatByRate = [];
        $vatTotal = $zero;

        foreach ($bases as $key => $base) {
            // THE ROUNDING POINT. PerRateGroup rounds the summed base once; PerLine sums figures that were
            // each already rounded. One `match`, both arms reached by the shared vectors.
            $groupVat = match ($vatRoundingPoint) {
                VatRoundingPoint::PerRateGroup => $prices->vat($base, $rates[$key], $mode),
                VatRoundingPoint::PerLine => $perLineVat[$key],
            };

            $vatByRate[] = new VatGroup($rates[$key], $base, $groupVat);
            $vatTotal = $vatTotal->plus($groupVat);
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

        return new DocumentTotals(
            $lineNets,
            $subtotalNet,
            $vatByRate,
            $vatTotal,
            $fixedChargesTotal,
            $total,
        );
    }

    /**
     * The document's single currency, inferred where possible and asserted everywhere.
     *
     * A document is single-currency by definition. `Money` would refuse the mixed arithmetic anyway, but it
     * would refuse it partway through a sum with a message about two amounts — this fails before any figure
     * is computed, so the error names the document rather than an intermediate value.
     *
     * @param list<DocumentLine> $lines
     * @param list<FixedCharge> $fixedCharges
     *
     * @throws CurrencyMismatch
     * @throws \InvalidArgumentException
     */
    private function resolveCurrency(array $lines, array $fixedCharges, ?Currency $currency): Currency
    {
        foreach ($lines as $line) {
            $currency ??= $line->unitNet()->currency();

            if (!$currency->equals($line->unitNet()->currency())) {
                throw CurrencyMismatch::between($currency, $line->unitNet()->currency());
            }
        }

        foreach ($fixedCharges as $charge) {
            $currency ??= $charge->amount()->currency();

            if (!$currency->equals($charge->amount()->currency())) {
                throw CurrencyMismatch::between($currency, $charge->amount()->currency());
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
