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
        $print = new PrintSettings('Merci', 'ymd', 'dot-comma', true, true, new DocumentDesign(DocumentLayout::Compact, '#2da44e'), true);

        self::assertEquals($print, PrintSettings::fromArray($print->toArray()));
    }

    public function testADocumentStoredBeforeHowToPayExistedReadsAsWithoutIt(): void
    {
        $older = ['printedNotes' => '', 'dateFormat' => 'auto', 'numberFormat' => 'auto', 'amountInWords' => true];

        self::assertFalse(PrintSettings::fromArray($older)->howToPay);
        self::assertFalse(PrintSettings::fromArray($older)->savingsLine);
        self::assertEquals(new DocumentDesign(), PrintSettings::fromArray($older)->design, 'and printed the classic way');
    }

    public function testADeliveryNoteDropsTheBlockAndKeepsTheRest(): void
    {
        $print = new PrintSettings('Merci', 'ymd', 'dot-comma', true, true, new DocumentDesign(DocumentLayout::Modern, '#1f6feb'), true);

        self::assertEquals(new PrintSettings('Merci', 'ymd', 'dot-comma', true, false, new DocumentDesign(DocumentLayout::Modern, '#1f6feb'), true), $print->withoutHowToPay());
    }

    public function testWhatTheDiscountsSaveIsPrintedOnlyWhenAskedAndSomethingIsTakenOff(): void
    {
        $asked = new PrintSettings('', 'auto', 'auto', savingsLine: true);

        self::assertSame('12.500', $asked->savingsPrinted('12.500'));
        self::assertNull($asked->savingsPrinted('0.000'), 'nothing taken off');
        self::assertNull($asked->savingsPrinted('-10.000'), 'a credit note gives money back');
        self::assertNull($asked->savingsPrinted(null), 'a document issued before it was kept');
        self::assertNull(new PrintSettings('', 'auto', 'auto')->savingsPrinted('12.500'), 'off unless the company asks');
    }
}
