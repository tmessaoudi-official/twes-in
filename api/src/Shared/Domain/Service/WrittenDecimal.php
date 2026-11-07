<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\Service;

use App\Shared\Domain\ValueObject\Refusal;

/**
 * Why a decimal a person typed is refused once its field's shape refused it, so they read what to change rather than
 * « Valeur refusée. »: below zero, finer than the field keeps, past its limit, or not a number at all. Only plain digits
 * are measured against the limit: « 1e3 » is a number to PHP and not one a person means.
 */
final class WrittenDecimal
{
    private const string PLAIN = '/^-?[0-9]+(\.[0-9]+)?$/';

    /** @param int|numeric-string|null $max the largest value the field keeps, when it has one */
    public static function refusal(string $typed, int $decimals, string $message, int|string|null $max = null): Refusal
    {
        if (1 !== preg_match(self::PLAIN, $typed) || !is_numeric($typed)) {
            return new Refusal('not_a_number', $message);
        }
        if (str_starts_with($typed, '-')) {
            return new Refusal('not_negative', $message);
        }
        if (null !== $max && bccomp($typed, (string) $max, $decimals) > 0) {
            return new Refusal('at_most', $message, ['max' => $max]);
        }
        $fraction = strpbrk($typed, '.');

        return false !== $fraction && \strlen($fraction) - 1 > $decimals
            ? new Refusal('too_many_decimals', $message, ['max' => $decimals])
            : new Refusal('not_a_number', $message);
    }
}
