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

        // AND THE UNIT PRICE, which is the SAME rule through the other door. Round 13 found the quantity
        // guarded and the price not, so `new DocumentLine('1', Money::of('-5.000', $tnd), ...)` produced a
        // line net of -5.000 and a document total of -5.950 — the third distinct route into the state the
        // 2026-07-30 ruling refuses. Round 12 closed the second (`fromNetPrice($cost, -5.000)`) and recorded
        // the lesson; `DocumentLine` takes a raw `Money`, so `ProductPricing`'s two guards are bypassed
        // entirely and a third guard is needed rather than a third comment about the first two.
        //
        // A negative-total document is a CREDIT NOTE — EN 16931 type code 381, not 380 — which is a
        // tax-document distinction rather than a presentation one.
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
