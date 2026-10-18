<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Legal\Application;

use App\Legal\Application\ReadLegalText;
use App\Legal\Domain\Model\LegalText;
use App\Legal\Domain\ValueObject\LegalLanguage;
use App\Legal\Domain\ValueObject\LegalPage;
use App\Tests\Support\InMemoryLegalTexts;
use PHPUnit\Framework\TestCase;

final class ReadLegalTextTest extends TestCase
{
    private InMemoryLegalTexts $texts;
    private ReadLegalText $read;

    protected function setUp(): void
    {
        $this->texts = new InMemoryLegalTexts();
        $this->read = new ReadLegalText($this->texts, \App\Tests\Support\ShippedLanguages::legal());
    }

    public function testTheLatestVersionOfThePageInTheLanguageAskedForIsRead(): void
    {
        $this->add(LegalPage::Cookies, LegalLanguage::of('en'), 'first', '2026-09-01 10:00');
        $this->add(LegalPage::Cookies, LegalLanguage::of('en'), 'second', '2026-09-20 10:00');
        $this->add(LegalPage::Privacy, LegalLanguage::of('en'), 'another page', '2026-09-25 10:00');
        $this->add(LegalPage::Cookies, LegalLanguage::of('fr'), 'autre langue', '2026-09-26 10:00');

        $text = $this->read->read(LegalPage::Cookies, LegalLanguage::of('en'));

        self::assertNotNull($text);
        self::assertSame('second', $text->getBody());
        self::assertEquals(LegalLanguage::of('en'), $text->getLanguage());
    }

    public function testAPageNotWrittenInTheLanguageAskedForFallsBackToFrenchFirst(): void
    {
        $this->add(LegalPage::Cookies, LegalLanguage::of('en'), 'english', '2026-09-01 10:00');
        $this->add(LegalPage::Cookies, LegalLanguage::of('fr'), 'français', '2026-09-01 10:00');

        $text = $this->read->read(LegalPage::Cookies, LegalLanguage::of('ar'));

        self::assertEquals(LegalLanguage::of('fr'), $text?->getLanguage());
    }

    public function testThenEnglish(): void
    {
        $this->add(LegalPage::Cookies, LegalLanguage::of('en'), 'english', '2026-09-01 10:00');

        self::assertEquals(LegalLanguage::of('en'), $this->read->read(LegalPage::Cookies, LegalLanguage::of('ar'))?->getLanguage());
        self::assertEquals(LegalLanguage::of('en'), $this->read->read(LegalPage::Cookies, LegalLanguage::of('fr'))?->getLanguage());
    }

    public function testAPageWrittenInNoLanguageIsNothing(): void
    {
        $this->add(LegalPage::Privacy, LegalLanguage::of('fr'), 'autre page', '2026-09-01 10:00');

        self::assertNull($this->read->read(LegalPage::Cookies, LegalLanguage::of('fr')));
    }

    public function testANewVersionStartsUnvalidatedAndValidatingItStampsWhoAndWhen(): void
    {
        $text = $this->add(LegalPage::Cookies, LegalLanguage::of('fr'), 'texte', '2026-09-01 10:00');
        self::assertFalse($text->isValidated());

        $text->validate('operator@twes.local', new \DateTimeImmutable('2026-09-02 09:00'));

        self::assertTrue($text->isValidated());
        self::assertSame('operator@twes.local', $text->getValidatedBy());
        self::assertEquals(new \DateTimeImmutable('2026-09-02 09:00'), $text->getValidatedAt());
    }

    private function add(LegalPage $page, LegalLanguage $language, string $body, string $at): LegalText
    {
        $text = LegalText::draft($page, $language, $body, null, new \DateTimeImmutable($at));
        $this->texts->add($text);

        return $text;
    }
}
