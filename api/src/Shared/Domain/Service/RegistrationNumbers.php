<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\Service;

/**
 * Registration numbers as a state's documents print them: without spaces, in capitals. A spreadsheet or a person writes
 * one spaced out (« 552 100 554 », « 1234567 a / b / m / 000 »), and no preset's pattern is written for either.
 */
final class RegistrationNumbers
{
    /**
     * Every space a keyboard or a spreadsheet writes, the no-break and the narrow no-break among them, and the invisible
     * characters a copy from a page or a PDF brings along (a zero-width space, a direction mark): `TypedText`'s set.
     */
    private const string SPACES = '/[\s\x00\p{Z}\p{Cf}]+/u';

    /**
     * @param array<array-key, string|null> $numbers by identifier key
     *
     * @return array<string, string> by identifier key, in key order, a blank one left out
     */
    public static function kept(array $numbers): array
    {
        $kept = [];
        foreach ($numbers as $key => $value) {
            $value = mb_strtoupper((string) preg_replace(self::SPACES, '', $value ?? ''));
            if ('' !== $value) {
                $kept[(string) $key] = $value;
            }
        }
        ksort($kept, \SORT_STRING);

        return $kept;
    }
}
