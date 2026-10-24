<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Service\TypedText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypedTextTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function writings(): iterable
    {
        yield 'plain spaces and tabs' => [" \t VIS-6 \n", 'VIS-6'];
        yield 'a no-break space' => ["\u{00A0}VIS-6\u{00A0}", 'VIS-6'];
        yield 'a narrow no-break space' => ["VIS-6\u{202F}", 'VIS-6'];
        yield 'a zero-width space' => ["\u{200B}VIS-6\u{200B}", 'VIS-6'];
        yield 'a byte-order mark' => ["\u{FEFF}VIS-6", 'VIS-6'];
        yield 'a word joiner' => ["VIS-6\u{2060}", 'VIS-6'];
        yield 'the bidirectional marks an Arabic keyboard writes' => ["\u{200F}VIS-6\u{200E}", 'VIS-6'];
        yield 'a soft hyphen' => ["VIS-6\u{00AD}", 'VIS-6'];
        yield 'an ideographic space' => ["\u{3000}VIS-6", 'VIS-6'];
        yield 'a NUL byte, as trim() takes it' => ["VIS-6\0", 'VIS-6'];
        yield 'what sits inside is kept' => ["\u{00A0}Vis\u{00A0}6 mm\u{200B}", "Vis\u{00A0}6 mm"];
        yield 'nothing but invisible characters is empty' => ["\u{200B}\u{00A0} \u{FEFF}", ''];
        yield 'an Arabic name keeps its letters' => ["\u{200F}شركة النور\u{200F}", 'شركة النور'];
    }

    #[DataProvider('writings')]
    public function testTheEndsLoseEverySpaceAndInvisibleCharacter(string $typed, string $kept): void
    {
        self::assertSame($kept, TypedText::trimmed($typed));
    }

    public function testBytesThatAreNoUtf8KeepTheirOwnRefusal(): void
    {
        self::assertSame("\xC3(", TypedText::trimmed(" \xC3( "), 'trimmed as trim() does, for the encoding check that names them');
    }

    public function testANullStaysNullForTheOptionalFields(): void
    {
        self::assertNull(TypedText::trimmedOrNull(null));
        self::assertNull(TypedText::trimmedOrNull("\u{200B} "));
        self::assertSame('Note', TypedText::trimmedOrNull("\u{00A0}Note"));
    }

    /** What a search compares: the ends trimmed, the case and the accents and vowel marks gone, nothing else. */
    public function testAFoldedTextKeepsItsLettersAlone(): void
    {
        self::assertSame('verification en deux etapes', TypedText::folded(" Vérification en deux ÉTAPES\u{00A0}"));
        self::assertSame('facture n°', TypedText::folded('Facture N°'));
        self::assertSame('فاتورة', TypedText::folded('فَاتُورَة'));
        self::assertSame('', TypedText::folded("\u{200B}"));
    }
}
