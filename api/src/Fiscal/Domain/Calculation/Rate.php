<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

use BcMath\Number;

/**
 * A rate held as a fraction with 12 decimals and written as a percentage with 10. Twelve, because six rounded a
 * real rate to zero: one millime of profit on 10,000.000 TND needs seven.
 */
final readonly class Rate
{
    public const int FRACTION_SCALE = 12;
    public const int PERCENTAGE_SCALE = 10;

    private function __construct(private Number $fraction)
    {
    }

    /** A percentage the way a person types it: "19", "5.5", "33.3333", "-20". */
    public static function fromPercentage(string $percentage): self
    {
        return new self(Decimal::round(Decimal::of($percentage)->div(100, Decimal::WORKING_SCALE), self::FRACTION_SCALE));
    }

    public static function fromFraction(Number $fraction): self
    {
        return new self(Decimal::round($fraction, self::FRACTION_SCALE));
    }

    public function fraction(): Number
    {
        return $this->fraction;
    }

    /** The canonical percentage, so "19" and "19.0" are the same rate everywhere they are compared. */
    public function percentage(): string
    {
        return Decimal::format($this->fraction->mul(100), self::PERCENTAGE_SCALE);
    }

    public function equals(self $other): bool
    {
        return 0 === $this->fraction->compare($other->fraction);
    }
}
