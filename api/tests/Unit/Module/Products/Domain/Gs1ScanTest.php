<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Domain;

use App\Module\Products\Domain\Gs1Scan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A scan read as GS1 where it is GS1 (docs/SPEC.md § 7, 2026-09-22 11:10): `(01)` finds the product, `(10)`, `(17)`
 * and `(21)` travel with it. The strings are the shapes scanners hand over, not this parser's own output.
 */
final class Gs1ScanTest extends TestCase
{
    private const string GS = "\x1D";

    /** @return iterable<string, array{string, string|null, string|null, string|null, string|null}> */
    public static function scans(): iterable
    {
        // A GS1-128 with its symbology prefix: fixed (01) and (17), the variable (10) closed by FNC1, then (21).
        yield 'GS1-128 with its prefix' => [']C101036123456789041727053110LOT-7'.self::GS.'21SN99', '03612345678904', 'LOT-7', '2027-05-31', 'SN99'];
        yield 'GS1 DataMatrix' => [']d2011001234567890210A1'.self::GS.'17260400', '10012345678902', 'A1', '2026-04-30', null];
        yield 'what a person types' => ['(01)03012345678902(10)LOT 7(17)261231', '03012345678902', 'LOT 7', '2026-12-31', null];
        yield 'separator without a prefix' => ['0103012345678902'.self::GS.'10L1', '03012345678902', 'L1', null, null];
        // An identifier outside what is read (a count, 30) is skipped by its length and the rest still read.
        yield 'an identifier skipped' => [']C1300012'.self::GS.'0103012345678902', '03012345678902', null, null, null];
    }

    #[DataProvider('scans')]
    public function testAGs1ScanIsReadIntoItsFields(string $scanned, ?string $gtin, ?string $lot, ?string $expiry, ?string $serial): void
    {
        $scan = Gs1Scan::read($scanned);

        self::assertTrue($scan->isGs1());
        self::assertSame([$gtin, $lot, $expiry, $serial], [$scan->gtin, $scan->lot, $scan->expiry(2026), $scan->serial]);
        self::assertSame($gtin, $scan->code());
    }

    /** @return iterable<string, array{string}> */
    public static function plainCodes(): iterable
    {
        yield 'an EAN-13' => ['3017620422003'];
        yield 'a GTIN-14 that starts like (01)' => ['01234567890128'];
        yield 'an internal code' => ['ABC-123/X'];
        // Brackets that do not spell GS1 all the way through are a plain code, never half read.
        yield 'brackets then text' => ['(01)03012345678902 extra'];
        yield 'a bracket that is no identifier' => ['(01)03012345678902(1)X'];
        yield 'a GTIN too short' => [']C1010361234567'];
        yield 'an unknown identifier' => [']C19912'];
    }

    #[DataProvider('plainCodes')]
    public function testAnythingElseIsAPlainCode(string $scanned): void
    {
        $scan = Gs1Scan::read($scanned);

        self::assertFalse($scan->isGs1());
        self::assertSame($scanned, $scan->code());
    }

    public function testTheCenturyOfAnExpiryFollowsGs1sSlidingWindow(): void
    {
        self::assertSame('2076-01-01', Gs1Scan::read('(17)760101')->expiry(2026));
        self::assertSame('1977-01-01', Gs1Scan::read('(17)770101')->expiry(2026));
        self::assertSame('2099-01-01', Gs1Scan::read('(17)990101')->expiry(2060));
        self::assertSame('2028-02-29', Gs1Scan::read('(17)280200')->expiry(2026), 'day 00 is the last of the month');
        self::assertNull(Gs1Scan::read('(17)261301')->expiry(2026), 'month 13 is no date');
    }
}
