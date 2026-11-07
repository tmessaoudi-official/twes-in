<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Venue\Domain;

use App\Venue\Domain\Exception\InvalidVenue;
use App\Venue\Domain\ValueObject\PlanRect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The floor's edge is checked on the body the plan shows, not on the unturned box behind it: a 6 m × 0.2 m wall
 * turned a quarter turn reaches 2.9 m left of its anchor, so it stands flush with the left edge at x = -2.9.
 */
final class PlanRectTest extends TestCase
{
    /** @return iterable<string, array{int, string, string}> the turn, the least x a 6 m × 0.2 m wall takes, and the first x refused */
    public static function wallTurns(): iterable
    {
        yield 'unturned' => [0, '0.000', '-0.001'];
        yield 'a quarter turn' => [90, '-2.900', '-2.901'];
        yield 'a half turn' => [180, '0.000', '-0.001'];
        yield 'three quarters' => [270, '-2.900', '-2.901'];
        // (6 cos 15° + 0.2 sin 15°) / 2 - 3 = -0.0763…
        yield 'fifteen degrees' => [15, '-0.076', '-0.077'];
    }

    #[DataProvider('wallTurns')]
    public function testATurnedWallStandsFlushWithTheLeftEdgeAndNoFurther(int $rotation, string $least, string $refused): void
    {
        $flush = new PlanRect($least, '3', '6', '0.2', $rotation, '3');
        self::assertSame($least, $flush->x, 'the body touches the left edge');

        try {
            new PlanRect($refused, '3', '6', '0.2', $rotation, '3');
            self::fail('A wall whose body leaves the floor by its left side was accepted.');
        } catch (InvalidVenue $refusal) {
            self::assertSame('x', $refusal->field);
        }
    }

    public function testATurnedWallStandsFlushWithTheTopEdgeAndNoFurther(): void
    {
        // Standing along the floor's depth and turned a quarter, the same wall lies across the top: 2.9 m above its anchor.
        self::assertSame('-2.900', new PlanRect('3', '-2.9', '0.2', '6', 90, '3')->y);

        try {
            new PlanRect('3', '-2.901', '0.2', '6', 90, '3');
            self::fail('A wall whose body leaves the floor by its top side was accepted.');
        } catch (InvalidVenue $refusal) {
            self::assertSame('y', $refusal->field);
        }
    }

    public function testAnAnchorOfZeroOrMoreIsTakenAsBefore(): void
    {
        // Every plan saved while the anchor was the bound stays valid, a turned wall at the corner included.
        $corner = new PlanRect('0', '0', '6', '0.2', 90, '3');

        self::assertSame(['0.000', '0.000'], [$corner->x, $corner->y]);
        self::assertSame('0.000', new PlanRect('-0', '-0.000', '6', '0.2', 0, '3')->x, 'no negative zero is kept');
    }

    /** @return iterable<string, array{callable(): PlanRect, string, string, array<string, string|int>}> */
    public static function refusals(): iterable
    {
        yield 'a turn past a whole one' => [static fn (): PlanRect => new PlanRect('3', '3', '6', '0.2', 360, '3'), 'rotation', 'whole_number_between', ['min' => 0, 'max' => 359]];
        yield 'a body past the left edge' => [static fn (): PlanRect => new PlanRect('-2.901', '3', '6', '0.2', 90, '3'), 'x', 'leaves_the_floor', []];
        yield 'a body past the top edge' => [static fn (): PlanRect => new PlanRect('3', '-2.901', '0.2', '6', 90, '3'), 'y', 'leaves_the_floor', []];
        yield 'a side of no length' => [static fn (): PlanRect => new PlanRect('3', '3', '0', '0.2', 0, '3'), 'width', 'above_zero', []];
        yield 'a side past the limit' => [static fn (): PlanRect => new PlanRect('3', '3', '6', '10000.001', 0, '3'), 'depth', 'at_most', ['max' => PlanRect::LIMIT]];
        yield 'a side written past the limit' => [static fn (): PlanRect => new PlanRect('3', '3', '123456', '0.2', 0, '3'), 'width', 'at_most', ['max' => PlanRect::LIMIT]];
        yield 'a side finer than the millimetre' => [static fn (): PlanRect => new PlanRect('3', '3', '6.0001', '0.2', 0, '3'), 'width', 'too_many_decimals', ['max' => PlanRect::SCALE]];
        yield 'a height below zero' => [static fn (): PlanRect => new PlanRect('3', '3', '6', '0.2', 0, '-1'), 'height', 'not_negative', []];
        yield 'a position in words' => [static fn (): PlanRect => new PlanRect('trois', '3', '6', '0.2', 0, '3'), 'x', 'not_a_number', []];
        yield 'a position below zero finer than the millimetre' => [static fn (): PlanRect => new PlanRect('-2.9001', '3', '6', '0.2', 90, '3'), 'x', 'too_many_decimals', ['max' => PlanRect::SCALE]];
    }

    /**
     * @param callable(): PlanRect      $drawn
     * @param array<string, string|int> $params
     */
    #[DataProvider('refusals')]
    public function testARefusedRectangleSaysWhyOnItsField(callable $drawn, string $field, string $reason, array $params): void
    {
        try {
            $drawn();
            self::fail('The rectangle was accepted.');
        } catch (InvalidVenue $refused) {
            self::assertSame([$field, $reason, $params], [$refused->field, $refused->reason, $refused->params]);
        }
    }

    public function testAPositionStillTakesAtMostThreeDecimalsAndStaysUnderTheLimit(): void
    {
        foreach (['-2.9001', '--1', '-', '-'.PlanRect::LIMIT.'1', '1,5'] as $x) {
            try {
                new PlanRect($x, '3', '6', '0.2', 90, '3');
                self::fail(\sprintf('The position %s was accepted.', $x));
            } catch (InvalidVenue $refusal) {
                self::assertSame('x', $refusal->field);
            }
        }
    }
}
