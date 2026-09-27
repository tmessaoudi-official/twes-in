<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Legal\Application;

use App\Legal\Application\ReadLegalText;
use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;
use App\Legal\Domain\LegalText;
use App\Tests\Support\InMemoryLegalTexts;
use PHPUnit\Framework\TestCase;

final class ReadLegalTextTest extends TestCase
{
    private InMemoryLegalTexts $texts;
    private ReadLegalText $read;

    protected function setUp(): void
    {
        $this->texts = new InMemoryLegalTexts();
        $this->read = new ReadLegalText($this->texts);
    }

    public function testTheLatestVersionOfThePageInTheLanguageAskedForIsRead(): void
    {
        $this->add(LegalPage::Cookies, LegalLanguage::En, 'first', '2026-09-01 10:00');
        $this->add(LegalPage::Cookies, LegalLanguage::En, 'second', '2026-09-20 10:00');
        $this->add(LegalPage::Privacy, LegalLanguage::En, 'another page', '2026-09-25 10:00');
        $this->add(LegalPage::Cookies, LegalLanguage::Fr, 'autre langue', '2026-09-26 10:00');

        $text = $this->read->read(LegalPage::Cookies, LegalLanguage::En);

        self::assertNotNull($text);
        self::assertSame('second', $text->getBody());
        self::assertSame(LegalLanguage::En, $text->getLanguage());
    }

    public function testAPageNotWrittenInTheLanguageAskedForFallsBackToFrenchFirst(): void
    {
        $this->add(LegalPage::Cookies, LegalLanguage::En, 'english', '2026-09-01 10:00');
        $this->add(LegalPage::Cookies, LegalLanguage::Fr, 'français', '2026-09-01 10:00');

        $text = $this->read->read(LegalPage::Cookies, LegalLanguage::Ar);

        self::assertSame(LegalLanguage::Fr, $text?->getLanguage());
    }

    public function testThenEnglish(): void
    {
        $this->add(LegalPage::Cookies, LegalLanguage::En, 'english', '2026-09-01 10:00');

        self::assertSame(LegalLanguage::En, $this->read->read(LegalPage::Cookies, LegalLanguage::Ar)?->getLanguage());
        self::assertSame(LegalLanguage::En, $this->read->read(LegalPage::Cookies, LegalLanguage::Fr)?->getLanguage());
    }

    public function testAPageWrittenInNoLanguageIsNothing(): void
    {
        $this->add(LegalPage::Privacy, LegalLanguage::Fr, 'autre page', '2026-09-01 10:00');

        self::assertNull($this->read->read(LegalPage::Cookies, LegalLanguage::Fr));
    }

    public function testANewVersionStartsUnvalidatedAndValidatingItStampsWhoAndWhen(): void
    {
        $text = $this->add(LegalPage::Cookies, LegalLanguage::Fr, 'texte', '2026-09-01 10:00');
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
