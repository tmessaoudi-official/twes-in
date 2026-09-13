<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

use BcMath\Number;

/**
 * Decimal arithmetic at a currency's scale, on PHP's arbitrary-precision BcMath\Number: half-up rounding (ties away
 * from zero, so a negative tie on a credit note rounds down), flooring, and the largest-remainder allocation every
 * split in docs/spec/pricing-vectors.json uses. Floats never touch money.
 */
final class Decimal
{
    /** The precision of an exact intermediate: a 12-decimal rate times an amount, or a division, before rounding. */
    public const int WORKING_SCALE = 24;

    private const string SHAPE = '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/';

    public static function of(string $value): Number
    {
        if (1 !== preg_match(self::SHAPE, $value) || !is_numeric($value)) {
            throw new InvalidDocument(\sprintf('"%s" is not a decimal number.', $value));
        }

        return new Number($value);
    }

    public static function zero(): Number
    {
        return new Number(0);
    }

    public static function round(Number $value, int $scale): Number
    {
        return $value->round($scale, \RoundingMode::HalfAwayFromZero);
    }

    /** Toward negative infinity, so a negative share floors away from zero and allocation never overshoots. */
    public static function floor(Number $value, int $scale): Number
    {
        $factor = self::factor($scale);

        return $value->mul($factor)->floor()->div($factor, $scale);
    }

    /** The value rounded and written with exactly `$scale` decimals: 0.000 for a dinar, never 0 or 0.00. */
    public static function format(Number $value, int $scale): string
    {
        $rounded = self::round($value, $scale);
        if (0 === $rounded->compare(0)) {
            $rounded = self::zero();
        }

        return (string) $rounded->add(0, $scale);
    }

    public static function absolute(Number $value): Number
    {
        return $value->compare(0) < 0 ? $value->mul(-1) : $value;
    }

    /** @param list<Number> $values */
    public static function sum(array $values): Number
    {
        return array_reduce($values, static fn (Number $total, Number $value) => $total->add($value), self::zero());
    }

    /**
     * Splits a rounded total into shares that add up to it exactly: floor each exact share to the scale, then hand
     * the shortfall out one smallest unit at a time to the largest floored-away remainders, ties to the earliest.
     * Flooring first is what keeps the shares from ever exceeding the total.
     *
     * @param list<Number> $exact the unrounded shares, in document order
     *
     * @return list<Number>
     */
    public static function allocate(Number $total, array $exact, int $scale): array
    {
        $unit = (new Number(1))->div(self::factor($scale), $scale);
        $floors = array_map(static fn (Number $share): Number => self::floor($share, $scale), $exact);
        $remainders = array_map(static fn (Number $share, Number $floor): Number => $share->sub($floor), $exact, $floors);
        $units = (int) (string) $total->sub(self::sum($floors))->div($unit, 0);

        $order = array_keys($exact);
        usort($order, static fn (int $a, int $b): int => $remainders[$b]->compare($remainders[$a]) ?: $a <=> $b);
        $topped = array_flip(\array_slice($order, 0, max(0, $units)));

        return array_map(
            static fn (Number $floor, int $i): Number => isset($topped[$i]) ? $floor->add($unit) : $floor,
            $floors,
            array_keys($floors),
        );
    }

    private static function factor(int $scale): Number
    {
        return (new Number(10))->pow($scale);
    }
}
