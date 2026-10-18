<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Legal\Application;

use App\Legal\Application\LegalLanguages;
use App\Legal\Domain\ValueObject\LegalLanguage;
use App\Tests\Support\ShippedLanguages;
use PHPUnit\Framework\TestCase;

final class LegalLanguagesTest extends TestCase
{
    private LegalLanguages $languages;

    protected function setUp(): void
    {
        $this->languages = new LegalLanguages(ShippedLanguages::catalogue());
    }

    public function testThePagesAreWrittenInTheLanguagesTheCatalogueMarksForThem(): void
    {
        self::assertSame(['fr', 'en', 'ar'], array_map(static fn (LegalLanguage $language) => $language->value, $this->languages->all()));
    }

    public function testOnlyALanguageOfTheCatalogueIsKnown(): void
    {
        self::assertSame('ar', $this->languages->known('ar')?->value);
        self::assertNull($this->languages->known('de'));
        self::assertNull($this->languages->known('AR'));
        self::assertNull($this->languages->known(''));
        self::assertNull($this->languages->known(null));
        self::assertNull($this->languages->known(['ar']));
    }

    /** A page missing in a language is read in the interface's languages, the default first, as people can read those. */
    public function testAPageMissingInALanguageIsReadInTheInterfaceLanguagesInOrder(): void
    {
        $readIn = fn (string $tag): array => array_map(static fn (LegalLanguage $language) => $language->value, $this->languages->readIn(LegalLanguage::of($tag)));

        self::assertSame(['ar', 'fr', 'en'], $readIn('ar'));
        self::assertSame(['en', 'fr', 'ar'], $readIn('en'));
        self::assertSame(['fr', 'en', 'ar'], $readIn('fr'));
    }

    public function testALanguageIsATagAndTwoOfTheSameTagAreEqual(): void
    {
        self::assertTrue(LegalLanguage::of('fr')->equals(LegalLanguage::of('fr')));
        self::assertFalse(LegalLanguage::of('fr')->equals(LegalLanguage::of('en')));
        self::assertSame('pt-BR', LegalLanguage::of('pt-BR')->value);

        $this->expectException(\InvalidArgumentException::class);
        LegalLanguage::of('FR');
    }
}
