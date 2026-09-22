<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/**
 * One code a product answers to (docs/SPEC.md § 7, 2026-09-17, 2026-09-22 11:05 and 22:26). The code is kept as it
 * was typed, because that is what is printed on the box and what a person recognises; what a scan is compared on is
 * its KEY, under which the spellings of one GTIN are the same code.
 *
 * A scanner reads one carton as a UPC-A `036000291452` or as its EAN-13 `0036000291452`, and a GTIN-14 carries the
 * same digits zero-padded: GS1 compares GTINs right-justified on fourteen digits, so the key does too. Compared on the
 * raw string, two products could each hold one spelling of one code and a scan would find both.
 */
final readonly class Barcode
{
    public const int MAX = 64;
    private const string PRINTABLE = '/^[\x21-\x7E]{1,64}$/';
    private const array GTIN_LENGTHS = [8, 12, 13, 14];

    public string $code;
    public string $key;

    /** @throws InvalidProduct */
    public function __construct(string $code)
    {
        $code = trim($code);
        if (1 !== preg_match(self::PRINTABLE, $code)) {
            throw new InvalidProduct('code', \sprintf('A barcode is 1 to %d printable characters without spaces.', self::MAX));
        }
        if (self::claimsGtin($code) && !self::checkDigitHolds($code)) {
            throw new InvalidProduct('code', 'This barcode has the shape of an EAN-13, EAN-8, UPC or GTIN-14 code and its check digit does not match.');
        }
        $this->code = $code;
        $this->key = self::keyOf($code);
    }

    /**
     * The key a scanned string is looked up by. It never refuses: a misread check digit is kept as it came, so it
     * matches nothing rather than failing the scan with a message about a code nobody typed.
     */
    public static function keyOf(string $scanned): string
    {
        $scanned = trim($scanned);
        if (self::claimsGtin($scanned) && self::checkDigitHolds($scanned)) {
            return str_pad($scanned, 14, '0', \STR_PAD_LEFT);
        }

        return $scanned;
    }

    public function equals(self $other): bool
    {
        return $this->key === $other->key;
    }

    /**
     * A code CLAIMS a GTIN when it is all digits at one of the four GTIN lengths. A code that claims nothing — an
     * internal reference, a Code 128 string, eleven digits — is kept as typed and compared as printed.
     *
     * Knowingly not detected: UPC-E is eight digits under a different rule, so a UPC-E code fails the EAN-8 check and
     * is refused rather than kept (docs/SPEC.md § 7, 2026-09-17).
     */
    private static function claimsGtin(string $code): bool
    {
        return \in_array(\strlen($code), self::GTIN_LENGTHS, true) && 1 === preg_match('/^[0-9]+$/', $code);
    }

    /**
     * One algorithm covers every GTIN length, because they share it: weights of 3 and 1 alternating from the digit
     * immediately left of the check digit, and the check digit is what brings the total to a multiple of ten.
     */
    private static function checkDigitHolds(string $code): bool
    {
        $digits = array_map(intval(...), str_split($code));
        $check = array_pop($digits);
        $sum = 0;
        // Right-aligned: the weight depends on the distance from the check digit, never on the code's length.
        foreach (array_reverse($digits) as $place => $digit) {
            $sum += $digit * (0 === $place % 2 ? 3 : 1);
        }

        return $check === (10 - $sum % 10) % 10;
    }
}
