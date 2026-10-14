<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Domain;

use App\Fiscal\Domain\Calculation\Decimal;
use BcMath\Number;
use PHPUnit\Framework\TestCase;

/** Shares that add up to the total they are given, whichever way it lies from what each share rounds to. */
final class DecimalTest extends TestCase
{
    public function testARoundedTotalGoesToTheLargestRemaindersFirst(): void
    {
        self::assertSame(['6.334', '6.333'], $this->shares('12.667', ['6.33365', '6.33365']));
        self::assertSame(['-6.334', '-6.333'], $this->shares('-12.667', ['-6.33365', '-6.33365']));
    }

    /** What is left of a tax can lie below what the shares floor to, or more than a unit a share above it. */
    public function testATotalBelowTheFloorsOrFarAboveThemStillAddsUp(): void
    {
        self::assertSame(['6.333', '6.332'], $this->shares('12.665', ['6.33365', '6.33365']));
        self::assertSame(['-9.500'], $this->shares('-9.500', ['-19.000']));
        self::assertSame(['6.336', '6.335'], $this->shares('12.671', ['6.33365', '6.33365']));
        self::assertSame([], $this->shares('0', []));
    }

    /**
     * @param list<string> $exact
     *
     * @return list<string>
     */
    private function shares(string $total, array $exact): array
    {
        return array_map(
            static fn (Number $share): string => Decimal::format($share, 3),
            Decimal::allocate(Decimal::of($total), array_map(static fn (string $each): Number => Decimal::of($each), $exact), 3),
        );
    }
}
