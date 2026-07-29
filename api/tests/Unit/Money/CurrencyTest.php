<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Money;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Exception\UnknownCurrency;

#[CoversClass(Currency::class)]
final class CurrencyTest extends TestCase
{
    #[DataProvider('scales')]
    public function testItKnowsEachCurrencysMinorUnitScale(string $code, int $expectedScale): void
    {
        self::assertSame($expectedScale, Currency::of($code)->scale());
    }

    /** @return iterable<string, array{string, int}> */
    public static function scales(): iterable
    {
        // The three-decimal set — the whole reason this class exists. TND is twes-in's default.
        yield 'TND — Tunisian dinar, the default currency' => ['TND', 3];
        yield 'BHD' => ['BHD', 3];
        yield 'IQD' => ['IQD', 3];
        yield 'JOD' => ['JOD', 3];
        yield 'KWD' => ['KWD', 3];
        yield 'LYD' => ['LYD', 3];
        yield 'OMR' => ['OMR', 3];

        // Two decimals — the majority, but never the assumption.
        yield 'EUR' => ['EUR', 2];
        yield 'USD' => ['USD', 2];
        yield 'GBP' => ['GBP', 2];
        yield 'CHF' => ['CHF', 2];
        yield 'MAD' => ['MAD', 2];
        yield 'DZD' => ['DZD', 2];

        // Zero decimals — an invoice for "1.50 JPY" is not a thing.
        yield 'JPY' => ['JPY', 0];
        yield 'KRW' => ['KRW', 0];
        yield 'XOF' => ['XOF', 0];
        yield 'XAF' => ['XAF', 0];
        yield 'CLP' => ['CLP', 0];
        yield 'ISK' => ['ISK', 0];
        yield 'VND' => ['VND', 0];

        // Four decimals — the reason the money column is NUMERIC(19,4) and not NUMERIC(19,3).
        yield 'CLF' => ['CLF', 4];
        yield 'UYW' => ['UYW', 4];
    }

    public function testTheThreeDecimalSetIsExactlyTheSevenIsoCurrencies(): void
    {
        // Pinned as a set, not as seven separate lookups: if a currency is ever added to the
        // registry with the wrong scale, this is the assertion that catches it.
        $threeDecimal = array_values(array_filter(
            Currency::all(),
            static fn(string $code): bool => 3 === Currency::of($code)->scale(),
        ));

        sort($threeDecimal);

        self::assertSame(['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'], $threeDecimal);
    }

    public function testItNormalisesLowercaseInput(): void
    {
        self::assertSame('TND', Currency::of('tnd')->code());
    }

    /**
     * An unknown code must never silently default to two decimals. That default is precisely the
     * bug this project cannot afford, so the registry refuses instead of guessing.
     */
    public function testAnUnknownCurrencyIsRefusedRatherThanAssumedToHaveTwoDecimals(): void
    {
        $this->expectException(UnknownCurrency::class);
        $this->expectExceptionMessage('ZZZ');

        Currency::of('ZZZ');
    }

    #[DataProvider('malformedCodes')]
    public function testItRefusesAMalformedCode(string $code): void
    {
        $this->expectException(UnknownCurrency::class);

        Currency::of($code);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['TN'];
        yield 'too long' => ['TNDX'];
        yield 'digits' => ['788'];
        yield 'padded' => [' TND'];
    }

    public function testEquality(): void
    {
        self::assertTrue(Currency::of('TND')->equals(Currency::of('tnd')));
        self::assertFalse(Currency::of('TND')->equals(Currency::of('EUR')));
    }

    public function testTheRegistryIsNotEmptyAndEveryEntryResolves(): void
    {
        $codes = Currency::all();

        self::assertNotEmpty($codes);

        foreach ($codes as $code) {
            self::assertSame($code, Currency::of($code)->code());
            self::assertGreaterThanOrEqual(0, Currency::of($code)->scale());
            self::assertLessThanOrEqual(4, Currency::of($code)->scale());
        }
    }
}
