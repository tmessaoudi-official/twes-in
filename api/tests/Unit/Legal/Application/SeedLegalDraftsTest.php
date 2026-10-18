<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Legal\Application;

use App\Legal\Application\LegalDraft;
use App\Legal\Application\LegalDrafts;
use App\Legal\Application\SeedLegalDrafts;
use App\Legal\Domain\Model\LegalText;
use App\Legal\Domain\ValueObject\LegalLanguage;
use App\Legal\Domain\ValueObject\LegalPage;
use App\Tests\Support\InMemoryLegalTexts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SeedLegalDraftsTest extends TestCase
{
    public function testADraftIsWrittenWhereNoVersionExistsAndNeverOverAnOperatorsText(): void
    {
        $texts = new InMemoryLegalTexts();
        $operators = LegalText::draft(LegalPage::Cookies, LegalLanguage::of('fr'), 'écrit par l’opérateur', 'operator@twes.local', new \DateTimeImmutable('2026-09-01'));
        $texts->add($operators);
        $drafts = new class implements LegalDrafts {
            public function all(): array
            {
                return [
                    new LegalDraft(LegalPage::Cookies, LegalLanguage::of('fr'), 'brouillon'),
                    new LegalDraft(LegalPage::Cookies, LegalLanguage::of('en'), 'draft'),
                ];
            }
        };
        $seed = new SeedLegalDrafts($drafts, $texts, new MockClock('2026-09-27 10:00'));

        self::assertSame(1, $seed->seed());
        self::assertSame(0, $seed->seed(), 'a second run writes nothing');

        self::assertSame($operators, $texts->latest(LegalPage::Cookies, LegalLanguage::of('fr')));
        $english = $texts->latest(LegalPage::Cookies, LegalLanguage::of('en'));
        self::assertSame('draft', $english?->getBody());
        self::assertNull($english->getCreatedBy(), 'a seeded draft was written by no one');
        self::assertFalse($english->isValidated());
        self::assertEquals(new \DateTimeImmutable('2026-09-27'), $english->getPublishedOn());
    }
}
