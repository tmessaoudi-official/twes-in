<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\DocumentDesign;
use App\Shared\Domain\DocumentLayout;
use App\Shared\Domain\PrintSettings;
use PHPUnit\Framework\TestCase;

/** What an issued document keeps of how it was printed survives its trip through the database. */
final class PrintSettingsTest extends TestCase
{
    public function testEveryChoiceSurvivesBeingStoredAndRead(): void
    {
        $print = new PrintSettings('Merci', 'ymd', 'dot-comma', true, true, new DocumentDesign(DocumentLayout::Compact, '#2da44e'));

        self::assertEquals($print, PrintSettings::fromArray($print->toArray()));
    }

    public function testADocumentStoredBeforeHowToPayExistedReadsAsWithoutIt(): void
    {
        $older = ['printedNotes' => '', 'dateFormat' => 'auto', 'numberFormat' => 'auto', 'amountInWords' => true];

        self::assertFalse(PrintSettings::fromArray($older)->howToPay);
        self::assertEquals(new DocumentDesign(), PrintSettings::fromArray($older)->design, 'and printed the classic way');
    }

    public function testADeliveryNoteDropsTheBlockAndKeepsTheRest(): void
    {
        $print = new PrintSettings('Merci', 'ymd', 'dot-comma', true, true, new DocumentDesign(DocumentLayout::Modern, '#1f6feb'));

        self::assertEquals(new PrintSettings('Merci', 'ymd', 'dot-comma', true, false, new DocumentDesign(DocumentLayout::Modern, '#1f6feb')), $print->withoutHowToPay());
    }
}
