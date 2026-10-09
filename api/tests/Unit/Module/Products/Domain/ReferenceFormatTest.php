<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Domain;

use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ReferenceFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferenceFormatTest extends TestCase
{
    public function testTheSequenceIsPaddedToItsWidthAndNeverCut(): void
    {
        self::assertSame('ART-00042', new ReferenceFormat('ART-{SEQ:5}')->render(42));
        self::assertSame('ART-123456', new ReferenceFormat('ART-{SEQ:5}')->render(123456));
        self::assertSame('7', new ReferenceFormat('{SEQ}')->render(7));
        self::assertSame('BOI/0003.A', new ReferenceFormat('BOI/{SEQ:4}.A')->render(3));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'no sequence' => ['ART-'];
        yield 'two sequences' => ['{SEQ}-{SEQ}'];
        yield 'a space' => ['ART {SEQ}'];
        yield 'an accent' => ['RÉF-{SEQ}'];
        yield 'a leading dash' => ['-{SEQ}'];
        yield 'another token' => ['{YYYY}-{SEQ}'];
        yield 'a zero width' => ['A{SEQ:0}'];
        yield 'too wide' => ['A{SEQ:13}'];
        yield 'too long' => ['ABCDEFGHIJKLMNO{SEQ}X'];
        yield 'empty' => [''];
    }

    #[DataProvider('refused')]
    public function testWhatCannotGiveAReferenceIsRefused(string $pattern): void
    {
        $this->expectException(InvalidProduct::class);
        new ReferenceFormat($pattern);
    }

    public function testTheLongestFormatStillGivesAReferenceAtTheLargestNumber(): void
    {
        foreach (['ABCDEFGHIJKLMNO{SEQ}', 'ABCDEFGHIJKL{SEQ:12}', '{SEQ:12}ABCDEFGHIJKL'] as $pattern) {
            self::assertSame(ReferenceFormat::MAX_LENGTH, \strlen($pattern));
            $reference = new ReferenceFormat($pattern)->render(\PHP_INT_MAX >> 32);
            self::assertMatchesRegularExpression(Product::REFERENCE, $reference, $pattern);
        }
    }

    public function testTheSettingsPatternAdmitsWhatTheFormatAdmits(): void
    {
        foreach (['ART-{SEQ:5}', '{SEQ}', 'BOI/{SEQ:4}.A', 'A{SEQ:12}'] as $pattern) {
            self::assertMatchesRegularExpression(ReferenceFormat::PATTERN, $pattern);
        }
        foreach (['ART-', '{SEQ}-{SEQ}', 'ART {SEQ}', '-{SEQ}', '{YYYY}-{SEQ}', 'A{SEQ:0}', 'A{SEQ:13}'] as $pattern) {
            self::assertDoesNotMatchRegularExpression(ReferenceFormat::PATTERN, $pattern);
        }
    }
}
