<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Shared;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twes\Infrastructure\Shared\FrozenClock;
use Twes\Infrastructure\Shared\SystemClock;
use Twes\Infrastructure\Shared\UuidV7Generator;

#[CoversClass(UuidV7Generator::class)]
#[CoversClass(FrozenClock::class)]
#[CoversClass(SystemClock::class)]
final class UuidV7GeneratorTest extends TestCase
{
    public function testItProducesAWellFormedUuid(): void
    {
        $id = new UuidV7Generator(new SystemClock())->nextIdentifier();

        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/',
            $id,
        );
    }

    public function testTheVersionNibbleIsSeven(): void
    {
        $id = new UuidV7Generator(new SystemClock())->nextIdentifier();

        // Position 14 is the first character of the third group — the version nibble.
        self::assertSame('7', $id[14], "Version must be 7, got: {$id}");
    }

    public function testTheVariantBitsAreRfc9562(): void
    {
        $id = new UuidV7Generator(new SystemClock())->nextIdentifier();

        // Position 19 is the first character of the fourth group. Its top two bits must be 10, so the
        // nibble is one of 8, 9, a, b.
        self::assertContains($id[19], ['8', '9', 'a', 'b'], "Variant must be RFC 9562, got: {$id}");
    }

    /**
     * The whole reason for choosing v7: the timestamp prefix is derived from the clock, so identifiers
     * minted later sort after ones minted earlier. A frozen clock makes that assertable rather than
     * flaky.
     */
    public function testTheTimestampPrefixComesFromTheClock(): void
    {
        $clock = FrozenClock::at('2026-07-29T12:00:00+00:00');
        $generator = new UuidV7Generator($clock);

        $early = $generator->nextIdentifier();

        $clock->advanceBy('PT1H');
        $late = $generator->nextIdentifier();

        self::assertLessThan(
            0,
            strcmp($early, $late),
            'A later identifier must sort after an earlier one — that is what makes v7 index-friendly.',
        );

        // The prefix is the clock reading in milliseconds, hex-encoded. Derived from the clock rather
        // than hardcoded: a hand-computed epoch literal is its own source of error, and the property
        // worth asserting is "the generator uses THIS clock's value", which this states directly.
        $clockMilliseconds = (int) FrozenClock::at('2026-07-29T12:00:00+00:00')->now()->format('Uv');
        $expectedPrefix = str_pad(dechex($clockMilliseconds), 12, '0', \STR_PAD_LEFT);

        self::assertSame(
            substr($expectedPrefix, 0, 8) . '-' . substr($expectedPrefix, 8, 4),
            substr($early, 0, 13),
            'The first 48 bits must be the clock reading in milliseconds.',
        );
    }

    public function testTwoIdentifiersFromTheSameFrozenInstantStillDiffer(): void
    {
        // Same millisecond, so only the 74 random bits separate them. If they collided, the randomness
        // is not being applied and every row created in one millisecond would clash.
        $generator = new UuidV7Generator(FrozenClock::at('2026-07-29T12:00:00+00:00'));

        $identifiers = [];

        for ($i = 0; $i < 1000; ++$i) {
            $identifiers[] = $generator->nextIdentifier();
        }

        self::assertCount(1000, array_unique($identifiers));
    }

    public function testTheSystemClockIsUtc(): void
    {
        self::assertSame('UTC', new SystemClock()->now()->getTimezone()->getName());
    }
}
