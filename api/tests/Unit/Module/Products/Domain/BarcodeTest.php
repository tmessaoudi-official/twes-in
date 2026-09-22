<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Domain;

use App\Module\Products\Domain\Barcode;
use App\Module\Products\Domain\InvalidProduct;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One code a product answers to (docs/SPEC.md § 7, 2026-09-22 11:05 and 22:26): kept as it was typed, and matched by
 * a key under which two spellings of one GTIN are the same code.
 */
final class BarcodeTest extends TestCase
{
    /**
     * Every GTIN length, and everything else kept as typed. The valid codes are published examples — Nutella's
     * EAN-13, the EAN-8 and UPC-A of the standards' own documentation — and a GTIN-14 over that UPC whose check digit
     * was worked out by hand, never this code's own output, which would only prove the rule agrees with itself.
     *
     * @return iterable<string, array{string}>
     */
    public static function keptCodes(): iterable
    {
        yield 'a real EAN-13' => ['3017620422003'];
        yield 'a real EAN-8' => ['96385074'];
        yield 'a real UPC' => ['036000291452'];
        yield 'a GTIN-14 on a carton' => ['10012345678902'];
        // Not a GTIN length, so no check digit is claimed and none is verified.
        yield 'eleven digits' => ['12345678901'];
        // Thirteen characters, but not thirteen DIGITS: an internal code, kept exactly as it was typed.
        yield 'thirteen with a letter' => ['301762042200A'];
        yield 'a Code 128 reference' => ['ABC-123/X'];
    }

    #[DataProvider('keptCodes')]
    public function testACodeIsKeptAsTypedUnlessItClaimsAGtinShapeAndFailsIt(string $code): void
    {
        self::assertSame($code, new Barcode(' '.$code.' ')->code);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedCodes(): iterable
    {
        yield 'empty' => ['  '];
        yield 'a space inside' => ['3017 620422003'];
        yield 'too long' => [str_repeat('1', Barcode::MAX + 1)];
        // One digit off a real code of each GTIN length: the check digit exists to catch exactly this.
        yield 'EAN-13 whose check digit is wrong' => ['3017620422004'];
        yield 'EAN-8 whose check digit is wrong' => ['96385075'];
        yield 'UPC whose check digit is wrong' => ['036000291453'];
        yield 'GTIN-14 whose check digit is wrong' => ['10012345678903'];
    }

    #[DataProvider('refusedCodes')]
    public function testAMalformedCodeIsRefusedNamingTheCode(string $code): void
    {
        $this->expectException(InvalidProduct::class);

        new Barcode($code);
    }

    /**
     * A scanner reads the same carton as a UPC-A or as its EAN-13 with a leading zero, and a GTIN-14 carries the same
     * digits padded: all three are ONE code. Keyed on the raw string, two products could each hold one spelling and a
     * scan would find two, which a scan may never do.
     */
    public function testTheSpellingsOfOneGtinShareTheirKey(): void
    {
        $key = new Barcode('036000291452')->key;

        self::assertSame('00036000291452', $key);
        self::assertSame($key, new Barcode('0036000291452')->key);
        self::assertSame($key, new Barcode('00036000291452')->key);
        self::assertSame('00000096385074', new Barcode('96385074')->key);
        // Anything that is not a GTIN is its own key, case included: an internal code is compared as printed.
        self::assertSame('ABC-123/x', new Barcode('ABC-123/x')->key);
        self::assertSame('12345678901', new Barcode('12345678901')->key);
    }

    public function testAScannedStringFindsTheSameKeyWithoutBeingRefused(): void
    {
        self::assertSame('00036000291452', Barcode::keyOf(' 0036000291452 '));
        // A misread check digit is not refused while scanning: it simply matches nothing.
        self::assertSame('036000291453', Barcode::keyOf('036000291453'));
    }
}
