<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\DocumentDesign;
use App\Shared\Domain\DocumentLayout;
use PHPUnit\Framework\TestCase;

/**
 * A printed document's design: its layout and its accent, from which the readable colours are derived, so that no
 * accent a company picks prints text that cannot be read (WCAG's 4.5:1 for text, which RGAA takes up).
 */
final class DocumentDesignTest extends TestCase
{
    public function testTheDefaultIsTheClassicLayoutInTheInkDocumentsAlwaysPrintedIn(): void
    {
        $design = new DocumentDesign();

        self::assertSame([DocumentLayout::Classic, '#1f2328', '#1f2328', '#ffffff'], [$design->layout, $design->accent, $design->accentOnPaper(), $design->inkOnAccent()]);
    }

    public function testTextOnTheAccentAndTheAccentOnPaperAreAlwaysReadable(): void
    {
        foreach (['#1f6feb', '#ffd33d', '#ffffff', '#000000', '#2da44e', '#cf222e', '#a5d6ff', '#808080'] as $accent) {
            $design = new DocumentDesign(DocumentLayout::Modern, $accent);

            self::assertGreaterThanOrEqual(4.5, DocumentDesign::contrast($design->inkOnAccent(), $accent), "ink on $accent");
            self::assertGreaterThanOrEqual(4.5, DocumentDesign::contrast($design->accentOnPaper(), '#ffffff'), "$accent on paper");
        }
        self::assertSame('#1f6feb', new DocumentDesign(DocumentLayout::Modern, '#1f6feb')->accentOnPaper(), 'an accent dark enough is kept as it is');
    }

    public function testTheTintIsTheAccentFaintOnPaper(): void
    {
        self::assertSame('#e9f1fd', new DocumentDesign(DocumentLayout::Modern, '#1f6feb')->tint());
    }

    public function testAnythingButAColourIsRefusedSinceItIsWrittenIntoTheStylesheet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DocumentDesign(DocumentLayout::Classic, 'red;}body{display:none');
    }

    public function testWhatADocumentKeptIsReadBackAndAnOlderOneReadsAsTheClassicDesign(): void
    {
        self::assertEquals(new DocumentDesign(DocumentLayout::Compact, '#2da44e'), DocumentDesign::fromArray(new DocumentDesign(DocumentLayout::Compact, '#2DA44E')->toArray()));
        self::assertEquals(new DocumentDesign(), DocumentDesign::fromArray([]));
    }
}
