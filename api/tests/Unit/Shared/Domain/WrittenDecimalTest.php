<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Service\WrittenDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A decimal its field's shape refused is told apart, so the person reads what to change rather than « Valeur refusée. »:
 * not a number, below zero, finer than the field keeps, or past its limit.
 */
final class WrittenDecimalTest extends TestCase
{
    /** @return iterable<string, array{string, int, int|numeric-string|null, string, array<string, string|int>}> */
    public static function refusals(): iterable
    {
        yield 'words' => ['douze', 3, null, 'not_a_number', []];
        yield 'a comma where the dot goes' => ['1,5', 3, null, 'not_a_number', []];
        yield 'scientific notation' => ['1e3', 3, '10000', 'not_a_number', []];
        yield 'below zero' => ['-2', 3, null, 'not_negative', []];
        yield 'four decimals of three' => ['1.2345', 3, null, 'too_many_decimals', ['max' => 3]];
        yield 'three decimals of two' => ['1.234', 2, null, 'too_many_decimals', ['max' => 2]];
        yield 'past the limit' => ['123456', 3, '10000', 'at_most', ['max' => '10000']];
        yield 'past a limit given as a whole number' => ['150', 3, 100, 'at_most', ['max' => 100]];
        yield 'past the limit, finer than kept too' => ['123456.1234', 3, '10000', 'at_most', ['max' => '10000']];
        yield 'digits a limit does not reach' => ['007', 3, '10000', 'not_a_number', []];
    }

    /**
     * @param int|numeric-string|null   $max
     * @param array<string, string|int> $params
     */
    #[DataProvider('refusals')]
    public function testSaysWhyATypedDecimalIsRefused(string $typed, int $decimals, int|string|null $max, string $reason, array $params): void
    {
        $refused = WrittenDecimal::refusal($typed, $decimals, 'A distance.', $max);

        self::assertSame([$reason, $params, 'A distance.'], [$refused->reason, $refused->params, $refused->message]);
    }
}
