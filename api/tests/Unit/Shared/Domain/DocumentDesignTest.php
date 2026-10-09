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
 * accent a company picks prints text that cannot be read (WCAG's 4.5:1 for text, which RGAA takes up), and the room its
 * logo may take.
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

    public function testTheLogoPrintsAtTheSizeTheCompanySetInMillimetresItsProportionsLockedByDefault(): void
    {
        $default = new DocumentDesign();
        self::assertSame([DocumentDesign::DEFAULT_LOGO_WIDTH_MM, DocumentDesign::DEFAULT_LOGO_HEIGHT_MM, true], [$default->logoWidthMm, $default->logoHeightMm, $default->logoKeepsProportions]);
        self::assertSame([48, 17], [$default->logoWidthMm, $default->logoHeightMm], 'the room documents gave a logo before it was a setting');

        $chosen = new DocumentDesign(DocumentLayout::Compact, '#2da44e', 60, 25, false);
        self::assertEquals($chosen, DocumentDesign::fromArray($chosen->toArray()), 'kept with an issued document');
        // A document issued before the size was a setting printed its logo in the room every document then gave it.
        self::assertEquals(new DocumentDesign(DocumentLayout::Compact, '#2da44e'), DocumentDesign::fromArray(['layout' => 'compact', 'accent' => '#2da44e']));
    }

    public function testALogoSizeNoPageCanHoldIsRefused(): void
    {
        foreach ([[DocumentDesign::LOGO_WIDTH_MM_MIN - 1, 17], [DocumentDesign::LOGO_WIDTH_MM_MAX + 1, 17], [48, DocumentDesign::LOGO_HEIGHT_MM_MIN - 1], [48, DocumentDesign::LOGO_HEIGHT_MM_MAX + 1]] as [$width, $height]) {
            try {
                new DocumentDesign(DocumentLayout::Classic, DocumentDesign::DEFAULT_ACCENT, $width, $height);
                self::fail("{$width} × {$height} mm is refused");
            } catch (\InvalidArgumentException) {
            }
        }
        $widest = new DocumentDesign(DocumentLayout::Classic, DocumentDesign::DEFAULT_ACCENT, DocumentDesign::LOGO_WIDTH_MM_MAX, DocumentDesign::LOGO_HEIGHT_MM_MAX);
        self::assertSame([DocumentDesign::LOGO_WIDTH_MM_MAX, DocumentDesign::LOGO_HEIGHT_MM_MAX], [$widest->logoWidthMm, $widest->logoHeightMm]);
    }
}
