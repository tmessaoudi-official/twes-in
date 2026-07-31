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

use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\Decimal;
use Twes\Domain\Shared\RoundingMode;

/**
 * One line of a document: a quantity, a unit price net of VAT, and the VAT rate that applies to it.
 *
 * **The quantity is a decimal STRING, not an int and never a float.** A quantity is routinely fractional —
 * 2.5 hours, 0.750 kg, 1.5 days — and it multiplies a money amount, so a float here would reintroduce the
 * one defect this domain exists to prevent at the exact point where it does the most damage. `Money` already
 * refuses a float by signature; this refuses one for the same reason.
 *
 * The rate lives on the LINE rather than the document. A document-level rate is the *default* a caller
 * applies when building lines, which is how the shared vectors express the single-rate cases — but multiple
 * rates on one document are the normal Tunisian and French case, so the line is where the rate belongs.
 *
 * Immutable, like everything else in this domain: an issued document's figures can never be moved by a later
 * edit to the product it was built from.
 */
final readonly class DocumentLine
{
    /**
     * The most decimals a quantity may carry — a **derived** bound, flagged as such because no plan rules it.
     *
     * Round 14 found `quantity` was the one persisted decimal in this domain with NO bound at either end:
     * `Money` caps integer digits at 15 and fractions at the currency's scale, `Rate` caps both, and a quantity
     * — which MULTIPLIES a money amount and is itself a line column — accepted 601 decimals and 40 integer
     * digits. So a value the domain accepted could not be stored by any `NUMERIC` a migration might choose, and
     * there was no constant for the migration to derive a precision FROM.
     *
     * Six, because a quantity is a count or a measure: hours, kilograms, cubic metres, litres. EN 16931's
     * invoiced quantity (BT-129) fixes no scale and practical Peppol implementations sit at 2–4, so six is
     * generous without being unbounded. **If the developer needs more, this is one constant and one column
     * type** — the point is that the bound EXISTS and is named, not that six is sacred.
     */
    public const int MAX_SCALE = 6;

    /**
     * Integer digits, matching `Money::MAX_INTEGER_DIGITS` deliberately.
     *
     * **This bound alone is NOT sufficient, and the sentence that used to sit here claiming it was is the reason
     * round 15 filed a P1.** It read: "a quantity that alone exceeds what an amount can hold cannot produce a
     * representable line net — so the refusal belongs here". False, and in the direction that matters:
     * `999999999999999` is accepted at exactly this bound, and multiplied by a unit price of `2.000` it gives
     * `1999999999999998.000` — SIXTEEN integer digits. `Invoice::issue()` computes nothing, so the invoice was
     * issued, its number consumed permanently and its state frozen, and `totals()` then raised forever;
     * `cancel()` did not help, so the audit record could never be rendered.
     *
     * That is verbatim the defect rounds 5 and 6 closed for `ProductPricing`, whose own docblock states the
     * remedy in the words this one ignored: **matching two bounds says nothing about their product.** So the
     * PRODUCT is checked too — see the constructor. This constant stays, because refusing an absurd quantity
     * with a message about the quantity is still better than one about an amount, but it is the cheap half.
     */
    public const int MAX_INTEGER_DIGITS = 15;
    /** Canonical decimal string. Never a float — see the constructor's float arm for why the union permits one. */
    private string $quantity;

    /**
     * @param string $quantity decimal string or integer-valued string; never a float
     *
     * @throws \InvalidArgumentException if the quantity is malformed or negative
     */
    public function __construct(
        string|int|float $quantity,
        private Money $unitNet,
        private Rate $vatRate,
    ) {
        // THE FLOAT ARM EXISTS TO REFUSE, and widening the union is what makes the refusal REACHABLE — the
        // same construction `Money::of()` and `Rate::fromPercentage()` use, and for the same reason. Round 13
        // found this parameter typed as a bare `string` while the docblock above claimed "never a float …
        // this refuses one for the same reason". It did not: from a caller without `declare(strict_types=1)`,
        // PHP coerced instead, and `0.1 + 0.2` — which is `0.30000000000000004` in IEEE-754 — arrived as the
        // string `'0.3'`, because implicit float-to-string uses `precision=14`. The float's actual value was
        // silently discarded, which is the exact laundering `Money`'s own float guard exists to stop.
        //
        // Worse, the refusal that DID happen was accidental: `1.0E+20` was rejected only because its
        // magnitude triggers exponent notation, which `isWellFormed()` refuses. So the invariant held for
        // some floats and not others. JSON decodes `"quantity": 1.5` as a PHP float, so this goes live the
        // moment a transport layer lands.
        if (\is_float($quantity)) {
            throw new \InvalidArgumentException(\sprintf(
                'Quantity %s is a float. A quantity multiplies a money amount, so a float here reintroduces '
                . 'the one defect this domain exists to prevent, at the point where it does the most damage. '
                . 'Pass a decimal string: 0.1 + 0.2 is 0.30000000000000004 in IEEE-754 and would arrive as '
                . '"0.3", silently discarding the value it actually held.',
                var_export($quantity, true),
            ));
        }

        $quantity = (string) $quantity;
        $this->quantity = $quantity;

        if (!Decimal::isWellFormed($quantity)) {
            throw new \InvalidArgumentException(\sprintf(
                'Quantity "%s" is not a well-formed decimal. A quantity is a decimal string — never a '
                . 'float, because it multiplies a money amount.',
                $quantity,
            ));
        }

        // A negative quantity is how a credit note gets expressed in some systems, and that is precisely why
        // it is refused HERE: twes-in has a Credit document type (Wave 2), so a negative line on an invoice
        // would be a second, unmodelled way to say the same thing — and the two would round differently.
        if (Decimal::isNegative($quantity)) {
            throw new \InvalidArgumentException(\sprintf(
                'Quantity "%s" is negative. A credit is its own document type (Wave 2), not a negative '
                . 'line on an invoice — two ways to express one thing is how the two drift apart.',
                $quantity,
            ));
        }

        // BOUNDED AT BOTH ENDS, which round 14 found it was not. See the two constants above: this is the only
        // persisted decimal in the domain that had no bound, and it is the one that multiplies money.
        if (Decimal::scaleOf($quantity) > self::MAX_SCALE) {
            throw new \InvalidArgumentException(\sprintf(
                'Quantity "%s" carries %d decimal places; at most %d are allowed. A quantity is a count or a '
                . 'measure, and one with more decimals than this cannot be stored by the line column at all — '
                . 'so accepting it here means the domain admits a value persistence will reject.',
                $quantity,
                Decimal::scaleOf($quantity),
                self::MAX_SCALE,
            ));
        }

        if (Decimal::integerDigits($quantity) > self::MAX_INTEGER_DIGITS) {
            throw new \InvalidArgumentException(\sprintf(
                'Quantity "%s" has %d integer digits; at most %d are allowed, matching Money. A quantity this '
                . 'large cannot produce a representable line net, and refusing it here names the quantity '
                . 'rather than surfacing later as an unrepresentable amount.',
                $quantity,
                Decimal::integerDigits($quantity),
                self::MAX_INTEGER_DIGITS,
            ));
        }

        // **THE PRODUCT, not just the two factors.** Round 15 found an invoice ISSUED — number consumed
        // permanently, state frozen — whose `totals()` then raised forever, because the two bounds above were
        // each satisfied while `quantity × unitNet` was not representable. `Invoice::issue()` computes no
        // figures, so nothing else stood between a legal document number and a document that can never be
        // rendered, and `cancel()` could not undo it.
        //
        // `RoundingMode::Up` is away-from-zero, so it yields the LARGEST magnitude any mode can produce for this
        // product — checking it therefore proves the line net is representable under EVERY mode the caller may
        // later pass to `net()`, which is what makes this a complete check rather than one with a carry edge left
        // open (a product at exactly 15 integer digits with a `.9995` tail rounds up to 16).
        //
        // Checked HERE rather than in `net()` because `net()` is called after issuing, and by then the number is
        // spent. The whole point is that an unrenderable document must be unconstructable.
        $exactProduct = Decimal::multiplyExact($quantity, $unitNet->amount());
        $atCurrencyScale = Decimal::rescale($exactProduct, $unitNet->currency()->scale(), RoundingMode::Up);

        // SPLIT FROM THE OVERFLOW CHECK, because they are different faults and round 16 filed them being merged.
        // `rescale()` returns null only under `RoundingMode::Unnecessary`, which this call does not use — so this
        // arm is unreachable today, and folding it into the message below would have stated something FALSE
        // ("gives %s, which has more integer digits") about a value that did not overflow, and keyed it as a
        // quantity error rather than `error.internal`. `ProductPricing` uses `?? throw new \LogicException` at the
        // same junction for exactly this reason: if the mode ever changes, it must fail loudly and honestly rather
        // than blame the user.
        $atCurrencyScale ??= throw new \LogicException(\sprintf(
            'Decimal::rescale() returned null for %s at scale %d under RoundingMode::Up, which it does not do. '
            . 'If the rounding mode above changed, this is our fault and not the caller\'s.',
            $exactProduct,
            $unitNet->currency()->scale(),
        ));

        if (Decimal::integerDigits($atCurrencyScale) > Money::MAX_INTEGER_DIGITS) {
            throw new \InvalidArgumentException(\sprintf(
                'Quantity "%s" times unit price %s gives %s, which has more integer digits than an amount can '
                . 'hold (%d). Both factors are individually within bounds — matching two bounds says nothing '
                . 'about their product — and refusing it here is what stops an invoice being ISSUED, its number '
                . 'consumed permanently, and its totals raising forever afterwards.',
                $quantity,
                $unitNet->amount(),
                $exactProduct,
                Money::MAX_INTEGER_DIGITS,
            ));
        }

        // A NEGATIVE VAT RATE. `Rate` permits negatives and is right to — it also serves as the PROFIT rate,
        // where "selling below cost" is a real commercial decision (clearance, a loss leader). But no
        // jurisdiction has a negative VAT rate, and `DocumentLine` performed no range check on the rate it was
        // handed, so `Rate::fromPercentage('-19')` produced a document with vat -19.000 and a total BELOW its
        // net. The same type serving two roles is why the constraint belongs at the use site rather than in
        // `Rate`: a rate is a dimensionless number, and what a VAT rate may be is a property of documents.
        if ($vatRate->isNegative()) {
            throw new \InvalidArgumentException(\sprintf(
                'VAT rate %s%% is negative. No jurisdiction has a negative VAT rate. `Rate` permits negatives '
                . 'because it also serves as the PROFIT rate, where selling below cost is legitimate — so the '
                . 'constraint belongs here, at the use site, not in Rate.',
                $vatRate->percentage(),
            ));
        }

        // AND THE UNIT PRICE, which is the SAME rule through the other door. Round 13 found the quantity
        // guarded and the price not, so `new DocumentLine('1', Money::of('-5.000', $tnd), ...)` produced a
        // line net of -5.000 and a document total of -5.950 — the third distinct route into the state the
        // 2026-07-30 ruling refuses. Round 12 closed the second (`fromNetPrice($cost, -5.000)`) and recorded
        // the lesson; `DocumentLine` takes a raw `Money`, so `ProductPricing`'s two guards are bypassed
        // entirely and a third guard is needed rather than a third comment about the first two.
        //
        // A negative-total document is a CREDIT NOTE — EN 16931 type code 381, not 380 — which is a
        // tax-document distinction rather than a presentation one.
        //
        // MOVED HERE at round 14. It sat ABOVE the negative-VAT-rate comment that round 13 inserted, so the
        // paragraph arguing the unit-price rule read as a preamble to the RATE guard and the unit-price guard
        // read as undocumented. In a codebase where the comment is the documentation, a rationale attached to
        // the wrong guard is the artifact that gets read once and believed.
        if ($unitNet->isNegative()) {
            throw new \InvalidArgumentException(\sprintf(
                'Unit price %s is negative. A negative line is how a credit note gets expressed in some '
                . 'systems, and twes-in has a Credit document type (Wave 2) instead — EN 16931 gives a credit '
                . 'note its own type code (381, not 380), so a negative line on an invoice is a second, '
                . 'unmodelled way to say the same thing.',
                $unitNet->amount(),
            ));
        }
    }

    public function quantity(): string
    {
        return $this->quantity;
    }

    public function unitNet(): Money
    {
        return $this->unitNet;
    }

    public function vatRate(): Rate
    {
        return $this->vatRate;
    }

    /**
     * The line's net: quantity times unit price, rounded to the currency once.
     *
     * Rounded HERE and not left exact, because the line net is a figure that is **printed on the document
     * and summed into the subtotal**. Keeping it exact and rounding only at the end would make the printed
     * lines fail to add up to the printed subtotal, which is the single most common complaint about
     * generated invoices and, for an EN 16931 payload, a validation failure rather than a cosmetic one.
     */
    public function net(RoundingMode $mode): Money
    {
        return $this->unitNet->multipliedBy($this->quantity, $mode);
    }
}
