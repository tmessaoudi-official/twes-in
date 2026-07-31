<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Money\Exception;

use Twes\Domain\Money\Currency;

final class CurrencyMismatch extends \LogicException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self(\sprintf(
            'Cannot combine %s with %s. Converting between currencies needs an exchange rate captured '
            . 'at the document date, which is an explicit operation and never an implicit one.',
            $left->code(),
            $right->code(),
        ));
    }

    /**
     * The same mismatch, but naming WHERE it was found — so a guard is distinguishable from the arithmetic
     * downstream of it.
     *
     * Round 14's reason for this existing: `DocumentCalculator::resolveCurrency()`'s two guards were entirely
     * deletable with the suite green, because `Money::plus()` throws {@see self::between()} three lines later and
     * the test asserted only the exception CLASS. So the guards' whole stated purpose — *"this asserts the kernel
     * surfaces that rather than catching it … the failure must name the document"* — was unpinned, and a crash
     * and a detection were indistinguishable. This repo has recorded that lesson twice already.
     *
     * `$context` is caller-supplied text rather than a document concept modelled here: `Domain/Money` must not
     * learn what a line or a charge is, so the caller says where it was and this class stays generic.
     */
    public static function inContext(Currency $expected, Currency $found, string $context): self
    {
        return new self(\sprintf(
            'Cannot combine %s with %s (%s). Converting between currencies needs an exchange rate captured '
            . 'at the document date, which is an explicit operation and never an implicit one.',
            $expected->code(),
            $found->code(),
            $context,
        ));
    }
}
