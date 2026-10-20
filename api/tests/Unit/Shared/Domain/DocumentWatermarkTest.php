<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\ValueObject\DocumentDesign;
use App\Shared\Domain\ValueObject\DocumentLayout;
use App\Shared\Domain\ValueObject\DocumentWatermark;
use App\Shared\Domain\ValueObject\WatermarkIntensity;
use App\Shared\Domain\ValueObject\WatermarkSpread;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The word a document's state prints across its pages (« BROUILLON », « DUPLICATA »): how strongly, how many times and
 * how the copies sit, which the company sets in its design. It stays readable as a state, never so faint it could be
 * missed, and its copies never run into each other.
 */
final class DocumentWatermarkTest extends TestCase
{
    /** The pages a document prints on: A4 less Gotenberg's margin of 0.4 in on every side, in millimetres. */
    private const float PAGE_WIDTH_MM = 210 - 2 * 10.16;
    private const float PAGE_HEIGHT_MM = 297 - 2 * 10.16;

    /** A letter of the bold capitals it prints in, with its letter spacing, and a line, as shares of the word's size. */
    private const float LETTER_EM = 0.82;
    private const float LINE_EM = 1.2;

    private const float POINT_MM = 25.4 / 72;

    public function testTheDefaultIsTheLookDocumentsAlwaysPrintedIn(): void
    {
        $watermark = new DocumentWatermark();

        self::assertSame([WatermarkIntensity::Normal, 1, WatermarkSpread::Grouped, '.14', 96], [$watermark->intensity, $watermark->copies, $watermark->spread, $watermark->opacity(), $watermark->wordSizePt()]);
        self::assertEquals($watermark, new DocumentDesign()->watermark);
    }

    public function testNoIntensityPrintsItFainterThanEightPerCent(): void
    {
        self::assertSame(['discreet' => 10, 'normal' => 14, 'marked' => 22], array_combine(
            array_map(static fn (WatermarkIntensity $each): string => $each->value, WatermarkIntensity::cases()),
            array_map(static fn (WatermarkIntensity $each): int => $each->percent(), WatermarkIntensity::cases()),
        ));
        foreach (WatermarkIntensity::cases() as $intensity) {
            self::assertGreaterThanOrEqual(WatermarkIntensity::FLOOR_PERCENT, $intensity->percent(), $intensity->value);
        }
        self::assertSame(8, WatermarkIntensity::FLOOR_PERCENT);
        self::assertSame(['.10', '.14', '.22'], array_map(static fn (WatermarkIntensity $each): string => new DocumentWatermark($each)->opacity(), WatermarkIntensity::cases()));
    }

    public function testItPrintsOnceOrThreeSixOrTwelveTimesAPage(): void
    {
        self::assertSame([1, 3, 6, 12], DocumentWatermark::COPIES);
        foreach ([0, 2, 4, 13, -1] as $copies) {
            try {
                new DocumentWatermark(WatermarkIntensity::Normal, $copies);
                self::fail("$copies copies are refused");
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testTheWordGrowsSmallerAsItIsRepeated(): void
    {
        $sizes = array_map(static fn (int $copies): int => new DocumentWatermark(WatermarkIntensity::Normal, $copies)->wordSizePt(), DocumentWatermark::COPIES);

        self::assertSame([96, 48, 32, 22], $sizes);
    }

    public function testSpreadCopiesFillAGridOfThePage(): void
    {
        self::assertSame([[1, 1], [1, 3], [2, 3], [3, 4]], array_map(static fn (int $copies): array => new DocumentWatermark(WatermarkIntensity::Normal, $copies, WatermarkSpread::Spread)->grid(), DocumentWatermark::COPIES));
        foreach (DocumentWatermark::COPIES as $copies) {
            [$columns, $rows] = new DocumentWatermark(WatermarkIntensity::Normal, $copies, WatermarkSpread::Spread)->grid();
            self::assertSame($copies, $columns * $rows);
        }
    }

    /**
     * Each spread copy, turned as it prints, stays inside its own cell of the page, and grouped copies stack as lines no
     * longer than the page: measured on the longest word any language prints, so no state in any language overlaps.
     */
    public function testNoCopyRunsIntoAnotherWhateverTheLanguage(): void
    {
        $letters = $this->longestWord();
        self::assertGreaterThanOrEqual(9, $letters, 'the words were read');
        [$cos, $sin] = [cos(deg2rad(30)), sin(deg2rad(30))];

        foreach (DocumentWatermark::COPIES as $copies) {
            $size = new DocumentWatermark(WatermarkIntensity::Normal, $copies)->wordSizePt() * self::POINT_MM;
            [$word, $line] = [$letters * self::LETTER_EM * $size, self::LINE_EM * $size];

            // A single copy is the look documents always printed in, whatever the spread: it has no cell of its own.
            if ($copies > 1) {
                [$columns, $rows] = new DocumentWatermark(WatermarkIntensity::Normal, $copies, WatermarkSpread::Spread)->grid();
                self::assertLessThanOrEqual(self::PAGE_WIDTH_MM / $columns, $word * $cos + $line * $sin, "$copies spread: across its cell");
                self::assertLessThanOrEqual(self::PAGE_HEIGHT_MM / $rows, $word * $sin + $line * $cos, "$copies spread: down its cell");
            }

            // Grouped, the copies are lines of one block turned on the diagonal: as wide as the word, as high as the lines.
            $block = $copies * DocumentWatermark::GROUPED_LINE_HEIGHT * $size;
            self::assertGreaterThan($line, DocumentWatermark::GROUPED_LINE_HEIGHT * $size, 'grouped lines leave room between them');
            self::assertLessThanOrEqual(hypot(self::PAGE_WIDTH_MM, self::PAGE_HEIGHT_MM), hypot($word, $block), "$copies grouped: on the page");
        }
    }

    public function testWhatADocumentKeptIsReadBackAndAnOlderOneReadsAsTheLookItPrintedIn(): void
    {
        $chosen = new DocumentDesign(DocumentLayout::Modern, '#2da44e', 60, 25, true, new DocumentWatermark(WatermarkIntensity::Marked, 6, WatermarkSpread::Spread));

        self::assertEquals($chosen, DocumentDesign::fromArray($chosen->toArray()));
        self::assertSame(['intensity' => 'marked', 'copies' => 6, 'spread' => 'spread'], $chosen->toArray()['watermark']);
        // Kept before the watermark was a setting, or bent: the look every document then printed in.
        self::assertEquals(new DocumentWatermark(), DocumentDesign::fromArray(['layout' => 'modern'])->watermark);
        self::assertEquals(new DocumentWatermark(), DocumentDesign::fromArray(['watermark' => ['intensity' => 'loud', 'copies' => 5, 'spread' => 7]])->watermark);
    }

    /** The most letters any state is printed in, in any language a document prints. */
    private function longestWord(): int
    {
        $longest = 0;
        foreach (glob(\dirname(__DIR__, 4).'/translations/pdf.*.yaml') ?: [] as $file) {
            $texts = Yaml::parseFile($file);
            self::assertIsArray($texts);
            self::assertIsArray($texts['watermark'] ?? null, $file);
            foreach ($texts['watermark'] as $word) {
                self::assertIsString($word);
                $longest = max($longest, mb_strlen($word));
            }
        }

        return $longest;
    }
}
