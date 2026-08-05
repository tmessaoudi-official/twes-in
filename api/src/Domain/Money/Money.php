<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Money;

use Twes\Domain\Money\Exception\CurrencyMismatch;
use Twes\Domain\Money\Exception\InvalidMoneyAmount;
use Twes\Domain\Shared\Decimal;
use Twes\Domain\Shared\RoundingMode;

/**
 * An exact amount of a specific currency.
 *
 * Two invariants hold for every instance, and everything else follows from them:
 *
 *  1. **The amount is exactly representable in its currency.** A TND amount has three decimals, a EUR
 *     amount two, a JPY amount none. There is no such thing as a `Money` carrying a value its currency
 *     could not print on an invoice — construction refuses rather than truncates, so corrupt data
 *     fails loudly instead of being laundered into a legal document.
 *  2. **Nothing rounds implicitly.** Addition and subtraction are exact by construction. Every
 *     operation that *can* lose a digit — multiplication by a rate, division — takes a
 *     {@see RoundingMode} as a required argument. There is no default, and no operation quietly picks
 *     one.
 *
 * Immutable: every operation returns a new instance.
 *
 * The internal representation is a decimal string and the arithmetic is bcmath, via {@see Decimal}.
 * That is an implementation detail on purpose — it appears in no signature here, so it can be replaced
 * without touching a single caller.
 */
final readonly class Money
{
    /** @param string $amount canonical decimal string, at exactly the currency's scale */
    private function __construct(
        private string $amount,
        private Currency $currency,
    ) {
        // Checked HERE and not only in of(), so it holds for RESULTS too: two representable amounts can
        // sum to an unrepresentable one, and every operation funnels through this constructor.
        if (Decimal::integerDigits($amount) > self::MAX_INTEGER_DIGITS) {
            throw InvalidMoneyAmount::outOfRange($amount);
        }
    }

    /**
     * Number of digits allowed before the decimal point.
     *
     * Money columns are `NUMERIC(19,4)` — 19 significant digits with 4 after the point, so 15 before
     * it. Enforced here rather than left to the database, because a value that passes domain validation
     * and then explodes at the persistence boundary is exactly the "corrupt data fails loudly" promise
     * broken: the failure arrives far from the code that caused it, mid-transaction, with no context.
     */
    public const int MAX_INTEGER_DIGITS = 15;

    /**
     * The amount must be exactly representable in the currency, and fit the money column.
     *
     * Accepts a decimal string or an integer. **A float is refused explicitly** — `float` is in the
     * signature only so that it can be rejected with a clear message. Leaving it out would be worse
     * than useless: PHP's union coercion prefers `int`, so from any caller without
     * `declare(strict_types=1)` a `string|int` signature turns `19.99` into `19` behind a `Deprecated`
     * notice that a production error handler swallows — silently making a 19.99 TND line 19.000 TND.
     *
     * Trailing zeroes beyond the currency's scale are fine (`0.1000` in TND is `0.100`); they lose
     * nothing. A significant digit beyond it is refused.
     *
     * @throws InvalidMoneyAmount if a float, malformed, out of range, or not representable in this
     *                            currency
     */
    public static function of(string|int|float $amount, Currency $currency): self
    {
        if (\is_float($amount)) {
            throw InvalidMoneyAmount::floatRefused($amount);
        }

        $value = (string) $amount;

        if (!Decimal::isWellFormed($value)) {
            throw InvalidMoneyAmount::malformed($value);
        }

        // Checked before rescaling as well as in the constructor, so the message names the value the
        // caller actually passed rather than its normalised form.
        if (Decimal::integerDigits($value) > self::MAX_INTEGER_DIGITS) {
            throw InvalidMoneyAmount::outOfRange($value);
        }

        $normalised = Decimal::rescale($value, $currency->scale(), RoundingMode::Unnecessary);

        if (null === $normalised) {
            throw InvalidMoneyAmount::notRepresentable($value, $currency);
        }

        return new self($normalised, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return self::of('0', $currency);
    }

    /** The amount as a canonical decimal string, at exactly the currency's scale. */
    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    // ---------------------------------------------------------------- exact operations

    /**
     * @throws CurrencyMismatch
     * @throws InvalidMoneyAmount if the SUM does not fit the money column
     *
     * The second tag was MISSING and the constructor's own comment eight lines up says why it should not have
     * been: *"Checked HERE and not only in of(), so it holds for RESULTS too: two representable amounts can sum
     * to an unrepresentable one."* `testAdditionThatOverflowsTheMoneyColumnIsRefused()` has proven that
     * behaviour since Wave 0 — only the contract denied it, so every caller documenting its own `@throws` by
     * reading this one under-reported. PHPStan found it from the other end, reporting
     * `PriceCalculator::grossFromNet()`'s correct `@throws InvalidMoneyAmount` as never thrown.
     */
    public function plus(self $addend): self
    {
        $this->assertSameCurrency($addend);

        return new self(
            Decimal::add($this->amount, $addend->amount, $this->currency->scale()),
            $this->currency,
        );
    }

    /**
     * @throws CurrencyMismatch
     * @throws InvalidMoneyAmount if the DIFFERENCE does not fit the money column
     *
     * See `plus()`. Subtraction grows the magnitude just as addition does whenever the operands' signs
     * differ — `9e14 - (-9e14)` is the same overflow written the other way round.
     */
    public function minus(self $subtrahend): self
    {
        $this->assertSameCurrency($subtrahend);

        return new self(
            Decimal::subtract($this->amount, $subtrahend->amount, $this->currency->scale()),
            $this->currency,
        );
    }

    /**
     * NO `@throws InvalidMoneyAmount`, and the omission is deliberate rather than the oversight `plus()` and
     * `minus()` carried: negation cannot change a magnitude, so an amount that fitted the column before still
     * fits it after. Do not add the tag by symmetry — PHPStan would then report it as never thrown.
     */
    public function negated(): self
    {
        return new self(
            Decimal::subtract('0', $this->amount, $this->currency->scale()),
            $this->currency,
        );
    }

    public function absolute(): self
    {
        return $this->isNegative() ? $this->negated() : $this;
    }

    // ---------------------------------------------------------------- lossy operations

    /**
     * Multiply by a plain decimal factor — a tax rate, a quantity, a multiplier.
     *
     * The factor is a decimal string or integer, not a `Money`: multiplying two amounts of money is
     * meaningless, and the result of this is money of the same currency.
     *
     * @param string|int $factor decimal string or integer
     * @param RoundingMode $mode required — the product usually has more decimals than the currency
     *
     * @throws InvalidMoneyAmount if the factor is malformed, rounding is needed under
     *                            RoundingMode::Unnecessary, or **the PRODUCT does not fit the money column**
     *
     * That third clause was missing, and it matters more here than on `plus()`: multiplication is the operation
     * on the actual VAT path (`PriceCalculator::vat()`) and grows a magnitude far faster than addition does.
     * The tag's TYPE was already present, so PHPStan was satisfied and no caller under-reported the class — only
     * the reason list was wrong, which is the half a static analyser cannot see.
     */
    public function multipliedBy(string|int|float $factor, RoundingMode $mode): self
    {
        // The float arm exists to REFUSE, exactly as in of(). Widening the union is what makes the refusal
        // reachable: with a bare `string|int`, PHP's union coercion prefers int, so a weak-mode caller's
        // 1.5 silently became 1 behind an E_DEPRECATED notice that a production error handler swallows.
        // Round 1 fixed of() and left this; round 5 proved the consequence — multipliedBy(1.5) on 10.000
        // returned 10.000 instead of 15.000, with no error anywhere.
        if (\is_float($factor)) {
            throw InvalidMoneyAmount::floatRefused($factor);
        }

        $value = (string) $factor;

        if (!Decimal::isWellFormed($value)) {
            throw InvalidMoneyAmount::malformed($value);
        }

        $product = Decimal::multiplyExact($this->amount, $value);
        $rounded = Decimal::rescale($product, $this->currency->scale(), $mode);

        if (null === $rounded) {
            throw InvalidMoneyAmount::roundingWouldLosePrecision($product, $this->currency);
        }

        return new self($rounded, $this->currency);
    }

    /**
     * @param string|int $divisor decimal string or integer
     *
     * @throws InvalidMoneyAmount if the divisor is malformed, rounding is needed under
     *                            RoundingMode::Unnecessary, or the QUOTIENT does not fit the money column —
     *                            see `multipliedBy()`; a divisor below 1 is a multiplication in disguise
     * @throws \DivisionByZeroError
     * @throws \LogicException if the divisor's own scale is large enough that `Decimal::divide()`'s DERIVED
     *                         working scale exceeds `Decimal::MAX_SCALE`. Documented rather than converted
     *                         (round 14 found it undeclared): unlike `Rate::fromPercentage()`, where the
     *                         scale was a CONSTANT the caller never chose and a `\LogicException` therefore
     *                         reached an HTTP boundary as a 500 for valid input, the scale here comes from
     *                         the caller's own divisor — so a programming-fault exception is the right type.
     *                         No caller exists in `Domain/` today; this becomes reachable when a transport
     *                         layer or an allocation/split feature calls it.
     */
    public function dividedBy(string|int|float $divisor, RoundingMode $mode): self
    {
        // The float arm exists to REFUSE, exactly as in of(). Widening the union is what makes the refusal
        // reachable: with a bare `string|int`, PHP's union coercion prefers int, so a weak-mode caller's
        // 1.5 silently became 1 behind an E_DEPRECATED notice that a production error handler swallows.
        // Round 1 fixed of() and left this; round 5 proved the consequence — multipliedBy(1.5) on 10.000
        // returned 10.000 instead of 15.000, with no error anywhere.
        if (\is_float($divisor)) {
            throw InvalidMoneyAmount::floatRefused($divisor);
        }

        $value = (string) $divisor;

        if (!Decimal::isWellFormed($value)) {
            throw InvalidMoneyAmount::malformed($value);
        }

        $quotient = Decimal::divide($this->amount, $value, $this->currency->scale(), $mode);

        if (null === $quotient) {
            throw InvalidMoneyAmount::roundingWouldLosePrecision(
                $this->amount . ' / ' . $value,
                $this->currency,
            );
        }

        return new self($quotient, $this->currency);
    }

    /**
     * What fraction of `$other` this amount is, as a plain decimal string.
     *
     * Deliberately **not** a `Money`: the ratio of two amounts is dimensionless, and returning money
     * here is how a rate ends up rounded to a currency's scale — losing the precision a profit rate or
     * a tax rate needs. The caller supplies the scale it needs, because only the caller knows whether
     * it is building a rate (TWELVE decimals -- see Rate::FRACTION_SCALE) or a proportion for an allocation.
     * Said "six" until round 12, which is the exact number a prior round ruled WAS the defect: six rounded a
     * real 0.0000001 rate to zero and deleted a millime of profit. Round 11 added lines immediately below
     * this sentence without reading it.
     *
     * @throws CurrencyMismatch
     * @throws InvalidMoneyAmount if rounding is needed under RoundingMode::Unnecessary
     * @throws \DivisionByZeroError if `$other` is zero — a caller that can hit zero must check first
     * @throws \LogicException if `$scale` is negative. A programming error at the call site, refused by
     *                         Decimal so a bcmath ValueError cannot escape the domain — see
     *                         Decimal::assertScale()
     */
    public function ratioTo(self $other, int $scale, RoundingMode $mode): string
    {
        $this->assertSameCurrency($other);

        $ratio = Decimal::divide($this->amount, $other->amount, $scale, $mode);

        if (null === $ratio) {
            // The SCALE-shaped factory, not the currency-shaped one. The failure is about the scale this
            // caller asked for; the currency is not involved, which is exactly why this method returns a
            // string rather than a Money. See the factory's docblock.
            throw InvalidMoneyAmount::roundingWouldLosePrecisionAtScale(
                $this->amount . ' / ' . $other->amount,
                $scale,
            );
        }

        return $ratio;
    }

    // ---------------------------------------------------------------- comparison

    /**
     * -1, 0 or 1.
     *
     * @throws CurrencyMismatch
     */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return Decimal::compare($this->amount, $other->amount);
    }

    /** @throws CurrencyMismatch */
    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /** @throws CurrencyMismatch */
    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /**
     * Amount and currency both equal.
     *
     * Unlike {@see self::compareTo()} this never throws — comparing across currencies for equality is
     * a reasonable question with a definite answer, which is "no".
     */
    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency)
            && 0 === Decimal::compare($this->amount, $other->amount);
    }

    public function isZero(): bool
    {
        return Decimal::isZero($this->amount);
    }

    public function isNegative(): bool
    {
        return Decimal::isNegative($this->amount);
    }

    public function isPositive(): bool
    {
        return !$this->isZero() && !$this->isNegative();
    }

    /** @throws CurrencyMismatch */
    private function assertSameCurrency(self $other): void
    {
        if (!$this->currency->equals($other->currency)) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
