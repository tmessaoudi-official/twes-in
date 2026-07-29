<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */



/*
 * NOTE THE ABSENCE OF declare(strict_types=1), AND DO NOT ADD IT.
 *
 * This file is the whole point of this test: it reproduces a caller in PHP's weak typing mode, which
 * is where the real defect lived. `Money::of()` used to be typed `string|int`, and the docblock claimed
 * "a float argument is a TypeError". That is a property of the CALLING file's strict_types, not of
 * Money — so MoneyTest.php, which is strict, could never detect the problem it claimed to cover.
 *
 * From a weak-mode caller, PHP's union coercion prefers `int`, so `Money::of(19.99, TND)` silently
 * produced 19.000 TND behind a Deprecated notice that any production error handler swallows. A wrong
 * amount on an invoice, from a caller that looked entirely reasonable.
 *
 * The fix is a `float` arm on the signature that throws explicitly. This file proves it, and it can
 * only prove it while it stays non-strict.
 *
 * php-cs-fixer's declare_strict_types rule is excluded for this path in .php-cs-fixer.dist.php.
 */

namespace Twes\Tests\Unit\Money;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Exception\InvalidMoneyAmount;
use Twes\Domain\Money\Money;

#[CoversClass(Money::class)]
final class MoneyWeakModeTest extends TestCase
{
    #[DataProvider('floats')]
    public function testAFloatIsRefusedEvenFromAWeakModeCaller(float $amount): void
    {
        $this->expectException(InvalidMoneyAmount::class);
        $this->expectExceptionMessageMatches('/float/');

        Money::of($amount, Currency::of('TND'));
    }

    /** @return iterable<string, array{float}> */
    public static function floats(): iterable
    {
        // Each of these previously truncated toward zero instead of failing.
        yield '19.99 became 19.000' => [19.99];
        yield '0.1 became 0.000' => [0.1];
        yield '0.001 became 0.000' => [0.001];
        yield 'a whole-number float is still a float' => [100.0];
        yield 'negative' => [-19.99];
    }

    /**
     * Guards this file's own premise.
     *
     * php-cs-fixer's `declare_strict_types` rule added the declaration here once despite a `notPath`
     * exclusion, and **every test in this file still passed** — because with strict types a float simply
     * never reaches `Money` and the explicit refusal is asserted for a case that can no longer occur. The
     * proof evaporated silently, which is the precise failure this project has already recorded once:
     * decide the condition in one place that every path reads, and never trust a guard whose failure mode
     * is a green test. So the property is asserted rather than configured.
     */
    public function testThisFileIsNotInStrictTypesModeBecauseThatIsTheWholePoint(): void
    {
        $source = file_get_contents(__FILE__);

        self::assertIsString($source);
        self::assertDoesNotMatchRegularExpression(
            '/^declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/m',
            $source,
            'declare(strict_types=1) has been added to this file. Remove it: with strict types a float '
            . 'never reaches Money::of(), so every float assertion below tests a case that cannot happen '
            . 'and the weak-mode coercion this file exists to catch goes unguarded again.',
        );
    }

    public function testAStringStillWorksFromAWeakModeCaller(): void
    {
        self::assertSame('19.990', Money::of('19.99', Currency::of('TND'))->amount());
    }

    public function testAnIntegerStillWorksFromAWeakModeCaller(): void
    {
        self::assertSame('100.000', Money::of(100, Currency::of('TND'))->amount());
    }
}
