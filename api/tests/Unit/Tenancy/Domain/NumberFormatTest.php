<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Domain;

use App\Tenancy\Domain\InvalidNumbering;
use App\Tenancy\Domain\NumberFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NumberFormat::class)]
final class NumberFormatTest extends TestCase
{
    public function testTheYearAndAPaddedSequenceMakeTheNumber(): void
    {
        self::assertSame('FAC-2026-00042', new NumberFormat('FAC-{YYYY}-{SEQ:5}')->render(42, new \DateTimeImmutable('2026-09-13'), '000'));
    }

    public function testTheShortYearTheMonthAndAnUnpaddedSequence(): void
    {
        self::assertSame('BL2601/7', new NumberFormat('BL{YY}{MM}/{SEQ}')->render(7, new \DateTimeImmutable('2026-01-31'), '000'));
    }

    public function testTheEstablishmentCodeKeepsTheNumbersOfTwoEstablishmentsApart(): void
    {
        self::assertSame('FAC-001-004', new NumberFormat('FAC-{EST}-{SEQ:3}')->render(4, new \DateTimeImmutable('2026-09-13'), '001'));
    }

    /**
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function pairs(): iterable
    {
        yield 'the same format, set apart by each establishment\'s code' => ['{EST}-FAC-{SEQ:5}', '000', '{EST}-FAC-{SEQ:5}', '001', false];
        yield 'the same format, nothing setting it apart' => ['FAC-{YYYY}-{SEQ:5}', '000', 'FAC-{YYYY}-{SEQ:5}', '001', true];
        yield 'one prints the other\'s numbers from 10 000 on' => ['FAC-{YYYY}-{SEQ:5}', '000', 'FAC-{YYYY}-1{SEQ:4}', '001', true];
        yield 'a sequence growing into another\'s prefix' => ['F{SEQ}', '000', 'F1{SEQ}', '001', true];
        yield 'a short year beside a long one' => ['F{YY}{SEQ:3}', '000', 'F{YYYY}{SEQ:3}', '001', true];
        yield 'the head office without its code, the branch with it' => ['FAC-{YYYY}-{MM}-{SEQ:5}', '000', 'FAC-{EST}-{YYYY}-{MM}-{SEQ:5}', '001', false];
        yield 'two prefixes' => ['A{SEQ}', '000', 'B{SEQ}', '001', false];
        yield 'a code as literal text where the other prints its own' => ['F001-{SEQ:3}', '000', 'F{EST}-{SEQ:3}', '001', true];
    }

    /** Whether two formats could ever print the same number, whatever their sequences, days and codes come to. */
    #[DataProvider('pairs')]
    public function testTwoFormatsMayPrintTheSameNumberOrNever(string $one, string $oneCode, string $other, string $otherCode, bool $shared): void
    {
        self::assertSame($shared, new NumberFormat($one)->mayPrintAsOf(new NumberFormat($other), $oneCode, $otherCode));
        self::assertSame($shared, new NumberFormat($other)->mayPrintAsOf(new NumberFormat($one), $otherCode, $oneCode));
    }

    public function testASequenceWiderThanItsPaddingIsNeverCut(): void
    {
        self::assertSame('AV-123456', new NumberFormat('AV-{SEQ:5}')->render(123456, new \DateTimeImmutable('2026-09-13'), '000'));
    }

    /** @return iterable<string, array{string}> */
    public static function refusedFormats(): iterable
    {
        yield 'no sequence' => ['FAC-{YYYY}'];
        yield 'two sequences' => ['{SEQ}-{SEQ:3}'];
        yield 'an unknown token' => ['FAC-{DD}-{SEQ}'];
        yield 'a character a number cannot carry' => ['FAC#{SEQ}'];
        yield 'no padding' => ['{SEQ:0}'];
        yield 'padding wider than twelve' => ['{SEQ:13}'];
        yield 'an unclosed brace' => ['FAC-{YYYY-{SEQ}'];
        yield 'too long' => [str_repeat('A', 60).'{SEQ}'];
        yield 'blank' => ['  '];
    }

    #[DataProvider('refusedFormats')]
    public function testAFormatThatCannotNumberADocumentIsRefused(string $format): void
    {
        $this->expectException(InvalidNumbering::class);

        new NumberFormat($format);
    }

    public function testTheRefusalNamesTheFormat(): void
    {
        try {
            new NumberFormat('FAC');
            self::fail('refused');
        } catch (InvalidNumbering $refused) {
            self::assertSame('format', $refused->field);
        }
    }
}
