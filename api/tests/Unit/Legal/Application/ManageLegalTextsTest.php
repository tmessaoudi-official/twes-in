<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Legal\Application;

use App\Legal\Application\EmptyLegalText;
use App\Legal\Application\LegalTextNotWritten;
use App\Legal\Application\ManageLegalTexts;
use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;
use App\Legal\Domain\LegalText;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryLegalTexts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManageLegalTextsTest extends TestCase
{
    private InMemoryLegalTexts $texts;
    private InMemoryAuditTrail $audit;
    private MockClock $clock;
    private ManageLegalTexts $manage;
    private Uuid $operator;

    protected function setUp(): void
    {
        $transactions = new FakeTransactions();
        $this->texts = new InMemoryLegalTexts();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->clock = new MockClock('2026-09-27 10:00');
        $this->manage = new ManageLegalTexts($this->texts, $this->audit, $transactions, $this->clock);
        $this->operator = Uuid::v7();
    }

    public function testWritingAddsAnUnvalidatedVersionByItsAuthorAndKeepsTheOlderOnes(): void
    {
        $first = $this->manage->write(LegalPage::Mentions, LegalLanguage::Fr, "Éditeur : à compléter\n", $this->operator, 'operator@twes.local');
        $this->clock->sleep(3600);
        $second = $this->manage->write(LegalPage::Mentions, LegalLanguage::Fr, 'Éditeur : twes', $this->operator, 'operator@twes.local');

        self::assertFalse($second->isValidated());
        self::assertSame('operator@twes.local', $second->getCreatedBy());
        self::assertSame([$second, $first], $this->manage->versions(LegalPage::Mentions, LegalLanguage::Fr));
        self::assertSame(['legal_text.written', 'legal_text.written'], array_map(static fn ($entry) => $entry->action, $this->audit->entries));
        self::assertSame(['page' => 'mentions', 'language' => 'fr'], $this->audit->entries[0]->changes);
        self::assertSame($this->operator, $this->audit->entries[0]->actorUserId);
    }

    public function testATextWithNothingInItIsRefused(): void
    {
        $this->expectException(EmptyLegalText::class);
        $this->manage->write(LegalPage::Mentions, LegalLanguage::Fr, "  \n ", $this->operator, 'operator@twes.local');
    }

    public function testValidatingMarksTheLatestVersionOnceAndRecordsIt(): void
    {
        $this->manage->write(LegalPage::Cookies, LegalLanguage::En, 'older', $this->operator, 'operator@twes.local');
        $this->clock->sleep(60);
        $latest = $this->manage->write(LegalPage::Cookies, LegalLanguage::En, 'latest', $this->operator, 'operator@twes.local');

        $validated = $this->manage->validate(LegalPage::Cookies, LegalLanguage::En, $this->operator, 'reviewer@twes.local');
        $again = $this->manage->validate(LegalPage::Cookies, LegalLanguage::En, $this->operator, 'someone@twes.local');

        self::assertSame($latest, $validated);
        self::assertSame($latest, $again);
        self::assertSame('reviewer@twes.local', $latest->getValidatedBy(), 'validating again changes nothing');
        self::assertSame(1, \count(array_filter($this->audit->entries, static fn ($entry) => 'legal_text.validated' === $entry->action)));
    }

    public function testValidatingAPageNeverWrittenIsRefused(): void
    {
        $this->expectException(LegalTextNotWritten::class);
        $this->manage->validate(LegalPage::Security, LegalLanguage::Ar, $this->operator, 'operator@twes.local');
    }

    public function testTheOverviewNamesEveryPageInEveryLanguageWithItsLatestVersionOrNone(): void
    {
        $this->texts->add(LegalText::draft(LegalPage::Cookies, LegalLanguage::Fr, 'old', null, new \DateTimeImmutable('2026-09-01')));
        $latest = LegalText::draft(LegalPage::Cookies, LegalLanguage::Fr, 'new', null, new \DateTimeImmutable('2026-09-20'));
        $this->texts->add($latest);

        $overview = $this->manage->overview();

        self::assertCount(\count(LegalPage::cases()) * \count(LegalLanguage::cases()), $overview);
        self::assertSame([LegalPage::Mentions, LegalLanguage::Fr, null], [$overview[0]->page, $overview[0]->language, $overview[0]->latest]);
        $cookies = array_values(array_filter($overview, static fn ($row) => LegalPage::Cookies === $row->page && LegalLanguage::Fr === $row->language));
        self::assertSame($latest, $cookies[0]->latest);
    }
}
