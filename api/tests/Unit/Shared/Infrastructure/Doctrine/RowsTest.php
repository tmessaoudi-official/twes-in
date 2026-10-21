<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\Rows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A raw row read as what its query names it, or refused: a cast or a fallback would turn a renamed column, a driver
 * change or a corrupt value into an empty name or a zero on the screen, which nobody would ever see as an error.
 */
final class RowsTest extends TestCase
{
    public function testTextAndCountsReadWhatTheDriverHandsOver(): void
    {
        self::assertSame('Vis', Rows::text('Vis'));
        self::assertSame('12', Rows::text(12));
        self::assertSame(12, Rows::int(12));
        self::assertSame(-3, Rows::int('-3'));
        self::assertTrue(Rows::bool(true));
        self::assertFalse(Rows::bool(false));
    }

    public function testANullableColumnReadsNullAsNothingAndRefusesAnythingElse(): void
    {
        self::assertNull(Rows::textOrNull(null));
        self::assertSame('BIAT', Rows::textOrNull('BIAT'));
        self::assertSame('7', Rows::textOrNull(7));

        $this->expectException(\UnexpectedValueException::class);
        Rows::textOrNull(['BIAT']);
    }

    public function testASumOfNoRowsIsZeroAndASumIsKeptAsTheDatabaseWroteIt(): void
    {
        self::assertSame('0', Rows::sum(null));
        self::assertSame('14', Rows::sum(14));
        self::assertSame('-2.5000000', Rows::sum('-2.5000000'));
    }

    public function testAnOrmResultIsReadAsTheEntitiesItsQuerySelects(): void
    {
        $one = new \ArrayObject();
        $two = new \ArrayObject();

        self::assertSame([$one, $two], Rows::entities([$one, $two], \ArrayObject::class));
        self::assertSame([], Rows::entities([], \ArrayObject::class));
    }

    public function testARowIsReadAsItsColumnsByName(): void
    {
        self::assertSame(['product' => 'p1', 'quantity' => '2.000'], Rows::row(['product' => 'p1', 'quantity' => '2.000']));
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function refused(): iterable
    {
        yield 'a name that came back empty' => [static fn (): mixed => Rows::text(null)];
        yield 'a count that is not whole' => [static fn (): mixed => Rows::int('2.5')];
        yield 'a count that came back empty' => [static fn (): mixed => Rows::int(null)];
        yield 'a flag read from text' => [static fn (): mixed => Rows::bool('t')];
        yield 'a sum that is not a number' => [static fn (): mixed => Rows::sum('n/a')];
        yield 'a sum read as a float' => [static fn (): mixed => Rows::sum(2.5)];
        yield 'a row that is not one' => [static fn (): mixed => Rows::row(false)];
        yield 'a result that is not a list' => [static fn (): mixed => Rows::entities(null, \ArrayObject::class)];
        yield 'a result holding something the query did not select' => [static fn (): mixed => Rows::entities([new \ArrayObject(), ['id' => 1]], \ArrayObject::class)];
    }

    #[DataProvider('refused')]
    public function testWhatTheQueryDoesNotNameIsRefusedNeverDefaulted(\Closure $read): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $read();
    }
}
