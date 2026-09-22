<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/**
 * What one scan says (docs/SPEC.md § 7, 2026-09-22 11:10): a plain code, or a GS1 element string whose application
 * identifiers are read rather than treated as one opaque code — `(01)` the GTIN, `(10)` the lot, `(17)` the use-by
 * date, `(21)` the serial — because the whole string matches no product while its `(01)` does.
 *
 * A scanner hands a GS1-128 or GS1 DataMatrix over in one of three shapes, and each is read:
 * - with the symbology identifier a scanner prefixes (`]C1` GS1-128, `]d2` GS1 DataMatrix, `]Q3` GS1 QR, `]e0`
 *   GS1 DataBar), the fields run together and a variable-length one ends at the group separator (FNC1, ASCII 29);
 * - with the separator but no prefix, when the first two digits are a known identifier and a separator is present;
 * - as the human-readable form with brackets, `(01)03012345678900(10)LOT-7`, which is what a person types.
 * Anything else is a plain code. Only the four identifiers the ruling names are kept; the others are skipped by
 * their known length, and a string using one whose length is not known here is left a plain code rather than
 * guessed at — a wrong guess would read a lot number into the GTIN.
 */
final readonly class Gs1Scan
{
    private const string GS = "\x1D";
    private const array SYMBOLOGY = [']C1', ']d2', ']Q3', ']e0', ']J1'];
    /** Fixed-length identifiers and the length of their data (the GS1 General Specifications list them). */
    private const array FIXED = ['00' => 18, '01' => 14, '02' => 14, '11' => 6, '12' => 6, '13' => 6, '15' => 6, '16' => 6, '17' => 6, '20' => 2];
    /** Variable-length identifiers read here and their maximum length. */
    private const array VARIABLE = ['10' => 20, '21' => 20, '22' => 20, '240' => 30, '241' => 30, '30' => 8, '37' => 8, '400' => 30];

    /**
     * @param string|null $gtin   the GTIN of `(01)`, fourteen digits
     * @param string|null $lot    `(10)`
     * @param string|null $useBy  `(17)` as written, YYMMDD; `expiry()` says which day it is
     * @param string|null $serial `(21)`
     */
    private function __construct(
        public string $raw,
        public ?string $gtin = null,
        public ?string $lot = null,
        public ?string $useBy = null,
        public ?string $serial = null,
    ) {
    }

    public static function read(string $scanned): self
    {
        $raw = trim($scanned, " \t\n\r\0\x0B");
        $body = $raw;
        $prefixed = false;
        foreach (self::SYMBOLOGY as $prefix) {
            if (str_starts_with($body, $prefix)) {
                $body = substr($body, \strlen($prefix));
                $prefixed = true;
                break;
            }
        }
        if (1 === preg_match('/^\(\d{2,4}\)/', $body)) {
            return self::fields($raw, self::bracketed($body)) ?? new self($raw);
        }
        if ($prefixed || (str_contains($body, self::GS) && 1 === preg_match('/^\d{2}/', $body))) {
            return self::fields($raw, self::runTogether($body)) ?? new self($raw);
        }

        return new self($raw);
    }

    public function isGs1(): bool
    {
        return null !== $this->gtin || null !== $this->lot || null !== $this->serial || null !== $this->useBy;
    }

    /** What to look the product up by: its GTIN when the scan carried one, else the scan as it came. */
    public function code(): string
    {
        return $this->gtin ?? $this->raw;
    }

    /** @return array<int|string, string>|null the fields by identifier; PHP makes "10" an int key, "01" stays a string */
    private static function bracketed(string $body): ?array
    {
        preg_match_all('/\((\d{2,4})\)([^(]*)/', $body, $parts, \PREG_SET_ORDER);
        $fields = [];
        $consumed = '';
        foreach ($parts as [$whole, $ai, $data]) {
            $fields[$ai] = $data;
            $consumed .= $whole;
        }

        return $consumed === $body ? $fields : null;
    }

    /** @return array<int|string, string>|null the fields by identifier; PHP makes "10" an int key, "01" stays a string */
    private static function runTogether(string $body): ?array
    {
        $fields = [];
        $at = 0;
        $length = \strlen($body);
        while ($at < $length) {
            if (self::GS === $body[$at]) {
                ++$at;
                continue;
            }
            $ai = self::identifierAt($body, $at);
            if (null === $ai) {
                return null;
            }
            $at += \strlen($ai);
            if (isset(self::FIXED[$ai])) {
                $data = substr($body, $at, self::FIXED[$ai]);
                if (\strlen($data) !== self::FIXED[$ai]) {
                    return null;
                }
                $at += self::FIXED[$ai];
            } else {
                $end = strpos($body, self::GS, $at);
                $data = false === $end ? substr($body, $at) : substr($body, $at, $end - $at);
                if (\strlen($data) > self::VARIABLE[$ai]) {
                    return null;
                }
                $at += \strlen($data);
            }
            $fields[$ai] = $data;
        }

        return $fields;
    }

    private static function identifierAt(string $body, int $at): ?string
    {
        foreach ([4, 3, 2] as $size) {
            $candidate = substr($body, $at, $size);
            if (isset(self::FIXED[$candidate]) || isset(self::VARIABLE[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<int|string, string>|null $fields */
    private static function fields(string $raw, ?array $fields): ?self
    {
        if (null === $fields || [] === $fields) {
            return null;
        }
        $gtin = $fields['01'] ?? null;
        if (null !== $gtin && 1 !== preg_match('/^\d{14}$/', $gtin)) {
            return null;
        }

        return new self($raw, $gtin, self::text($fields['10'] ?? null), self::useBy($fields['17'] ?? null), self::text($fields['21'] ?? null));
    }

    /** `(17)` as written when it is six digits; anything else is no date. */
    private static function useBy(?string $value): ?string
    {
        return null !== $value && 1 === preg_match('/^\d{6}$/', $value) ? $value : null;
    }

    private static function text(?string $value): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }

    /**
     * YYMMDD, the day 00 read as the last of its month. The century is GS1's sliding window: the year is placed
     * within 49 years before and 50 after this one.
     */
    public function expiry(int $thisYear): ?string
    {
        if (null === $this->useBy || 1 !== preg_match('/^(\d{2})(\d{2})(\d{2})$/', $this->useBy, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return null;
        }
        $year = intdiv($thisYear, 100) * 100 + (int) $m[1];
        if ($year - $thisYear > 50) {
            $year -= 100;
        } elseif ($thisYear - $year > 49) {
            $year += 100;
        }
        $month = (int) $m[2];
        $day = '00' === $m[3] ? (int) (new \DateTimeImmutable(\sprintf('%d-%02d-01', $year, $month)))->format('t') : (int) $m[3];

        return checkdate($month, $day, $year) ? \sprintf('%d-%02d-%02d', $year, $month, $day) : null;
    }
}
