<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain;

/**
 * A check a registration number carries beyond its shape, named by a fiscal preset (docs/fiscal/<CC>.md). A value is
 * checked only once it has the shape its preset gives it; a check never replaces the pattern.
 */
enum IdentifierCheck: string
{
    /** The Luhn check digit: a French SIREN. */
    case Luhn = 'luhn';
    /** Luhn over all fourteen digits, except La Poste's establishments, whose digits add up to a multiple of five. */
    case Siret = 'siret';
    /** A French intra-EU VAT number: its two-digit key is (12 + 3 × (SIREN mod 97)) mod 97. */
    case FrenchVatKey = 'fr_vat_key';

    private const string LA_POSTE_SIREN = '356000000';

    public function accepts(string $value): bool
    {
        return match ($this) {
            self::Luhn => self::luhn($value),
            self::Siret => str_starts_with($value, self::LA_POSTE_SIREN) && ctype_digit($value)
                ? 0 === array_sum(array_map('intval', str_split($value))) % 5
                : self::luhn($value),
            self::FrenchVatKey => self::frenchVatKey($value),
        };
    }

    private static function luhn(string $digits): bool
    {
        if (!ctype_digit($digits)) {
            return false;
        }
        $sum = 0;
        $double = false;
        for ($i = \strlen($digits) - 1; $i >= 0; --$i) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }
            $sum += $digit;
            $double = !$double;
        }

        return 0 === $sum % 10;
    }

    /** A key of letters belongs to a number issued without a SIREN, which this formula does not cover: it passes. */
    private static function frenchVatKey(string $value): bool
    {
        if (1 !== preg_match('/^FR([0-9A-Z]{2})([0-9]{9})$/', $value, $parts)) {
            return false;
        }
        if (!ctype_digit($parts[1])) {
            return true;
        }

        return (int) $parts[1] === (12 + 3 * ((int) $parts[2] % 97)) % 97;
    }
}
