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
use Twes\Domain\Pricing\Exception\InvalidRate;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Shared\RoundingMode;

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

    /**
     * EVERY money-path parameter, not just `Money::of()`.
     *
     * Round 1 found this defect, fixed it on ONE of five sites, and left four. Round 5 proved what that cost:
     * a bare `string|int` union coerces a weak-mode caller's float to int, so
     *
     *     Money::of('10.000', TND)->multipliedBy(1.5, HalfUp)   ->  10.000   (should be 15.000)
     *     Rate::fromFraction(0.30)                              ->  0%       (should be 30%)
     *
     * Both silently, behind an `E_DEPRECATED` that this container's default `error_reporting` does not even
     * print. `Rate::fromFraction(0.30)` returning zero is the worst of them: it is the F4 defect the whole
     * `authored_by` design exists to eliminate, reintroduced through a different door.
     *
     * Table-driven over ALL of them, so the next parameter added to a money path is a visibly missing row
     * rather than a silent gap — which is exactly how four sites survived four certification rounds.
     *
     * @param callable(float): mixed $call
     */
    #[DataProvider('everyMoneyPathParameter')]
    public function testEveryMoneyPathParameterRefusesAFloatFromAWeakModeCaller(
        string $description,
        callable $call,
        string $expectedException,
    ): void {
        // A float that is NOT a whole number, deliberately: 2.0 coerces to int losslessly and emits no
        // diagnostic at all, so a suite written with integral values would pass against the unfixed code.
        try {
            $result = $call(1.5);
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($expectedException, $thrown, $description);
            self::assertStringContainsString('float', $thrown->getMessage(), $description);

            return;
        }

        self::fail(\sprintf(
            '%s accepted the float 1.5 and returned %s instead of refusing it. A weak-mode caller has '
            . 'silently truncated it to 1.',
            $description,
            \is_object($result) ? get_class($result) : var_export($result, true),
        ));
    }

    /** @return iterable<string, array{string, callable, class-string<\Throwable>}> */
    public static function everyMoneyPathParameter(): iterable
    {
        $tnd = Currency::of('TND');

        yield 'Money::of' => [
            'Money::of',
            static fn(float $f): mixed => Money::of($f, $tnd),
            InvalidMoneyAmount::class,
        ];
        yield 'Money::multipliedBy' => [
            'Money::multipliedBy',
            static fn(float $f): mixed => Money::of('10.000', $tnd)->multipliedBy($f, RoundingMode::HalfUp),
            InvalidMoneyAmount::class,
        ];
        yield 'Money::dividedBy' => [
            'Money::dividedBy',
            static fn(float $f): mixed => Money::of('10.000', $tnd)->dividedBy($f, RoundingMode::HalfUp),
            InvalidMoneyAmount::class,
        ];
        yield 'Rate::fromPercentage' => [
            'Rate::fromPercentage',
            static fn(float $f): mixed => Rate::fromPercentage($f),
            InvalidRate::class,
        ];
        yield 'Rate::fromFraction' => [
            'Rate::fromFraction',
            static fn(float $f): mixed => Rate::fromFraction($f),
            InvalidRate::class,
        ];
    }

    /**
     * The signatures themselves, by reflection — a second, independent guard.
     *
     * The test above proves each parameter refuses a float TODAY. This proves the mechanism that makes the
     * refusal reachable: a `float` arm on the union. Without it the refusal is unreachable because PHP
     * coerces before the method body runs, so a future "tidy the signature" edit that narrows the union back
     * to `string|int` would make every case above pass for the wrong reason — the float would become an int
     * before any guard could see it, and nothing would throw at all... except that the value would be wrong.
     * Reflection catches that; behaviour alone cannot.
     */
    public function testEveryMoneyPathSignatureAcceptsFloatSoThatItCanRefuseIt(): void
    {
        foreach ([
            [Money::class, 'of', 'amount'],
            [Money::class, 'multipliedBy', 'factor'],
            [Money::class, 'dividedBy', 'divisor'],
            [Rate::class, 'fromPercentage', 'percentage'],
            [Rate::class, 'fromFraction', 'fraction'],
        ] as [$class, $method, $parameter]) {
            $type = (string) (new \ReflectionMethod($class, $method))->getParameters()[0]->getType();

            self::assertStringContainsString(
                'float',
                $type,
                \sprintf(
                    '%s::%s($%s) is typed "%s". Without a float arm the guard inside is unreachable: PHP '
                    . 'coerces the float to int before the body runs, so a wrong number is produced with no '
                    . 'exception at all.',
                    $class,
                    $method,
                    $parameter,
                    $type,
                ),
            );
        }
    }
}
