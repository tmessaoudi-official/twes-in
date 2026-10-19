<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Domain;

use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\ValueObject\PrintedNames;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrintedNamesTest extends TestCase
{
    public function testWhatADocumentKeptReadsBackAsItWasKept(): void
    {
        $names = new PrintedNames(['C62' => 'unité', 'KGM' => 'kg'], ['TVA19' => 'TVA', 'TIMBRE' => 'Timbre fiscal']);

        $read = PrintedNames::fromArray($names->toArray());

        self::assertEquals($names, $read);
        self::assertSame('kg', $read->unit($this->unit('KGM')));
    }

    /**
     * A unit on a document's lines that its kept names do not name is a document kept wrong: said loudly, never printed
     * under another name.
     */
    public function testAUnitTheDocumentDidNotKeepIsRefused(): void
    {
        $names = new PrintedNames(['C62' => 'unité'], []);

        $this->expectException(\LogicException::class);
        $names->unit($this->unit('KGM'));
    }

    /** @param array<array-key, mixed> $kept */
    #[DataProvider('keptWrong')]
    public function testNamesKeptInAnotherShapeAreRefused(array $kept): void
    {
        $this->expectException(\UnexpectedValueException::class);
        PrintedNames::fromArray($kept);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function keptWrong(): iterable
    {
        yield 'no units' => [['taxes' => []]];
        yield 'no taxes' => [['units' => []]];
        yield 'a unit named by a number' => [['units' => ['C62' => 3], 'taxes' => []]];
        yield 'a tax keyed by position' => [['units' => [], 'taxes' => ['TVA']]];
        yield 'units as a word' => [['units' => 'unité', 'taxes' => []]];
    }

    private function unit(string $code): Unit
    {
        return Unit::create(new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'), $code, $code, 0, 0, new \DateTimeImmutable('2026-10-19'));
    }
}
