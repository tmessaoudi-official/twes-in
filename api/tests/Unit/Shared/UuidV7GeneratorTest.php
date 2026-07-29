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

    /**
     * The five hyphen-separated groups must PARTITION the 32 hex characters, not overlap.
     *
     * Nothing asserted this, so the generator could silently stop carrying most of its randomness: with the
     * final group sliced from offset 0 instead of 20, the emitted id is
     * `PPPPPPPP-PPPP-7rrr-8rrr-PPPPPPPPPPPP` — the 48 random bits of bytes 10–15 never reach the output and the
     * last group is a constant at a fixed millisecond, leaving **26** usable random bits instead of 74. The
     * format regex, the version nibble, the variant nibble, the clock prefix and 1000-unique-ids all still
     * pass, because the middle 26 bits vary.
     *
     * That matters beyond entropy: this class's docblock sells those 74 bits as a tenancy control — a missing
     * authorisation check on `/invoices/1234` is enumerable, on a UUID it is not — and nothing could falsify it.
     *
     * Deterministic, no statistics: at a FROZEN clock the timestamp prefix is identical, so any group that
     * echoes it is caught by a single inequality.
     */
    public function testTheGroupsPartitionTheHexRatherThanRepeatingTheTimestamp(): void
    {
        $generator = new UuidV7Generator(FrozenClock::at('2026-07-29T12:00:00+00:00'));

        $first = $generator->nextIdentifier();
        $second = $generator->nextIdentifier();

        $groups = explode('-', $first);
        self::assertCount(5, $groups);

        // The two ids share a millisecond, so their timestamp prefix is identical — and everything after it
        // must not be.
        self::assertSame(
            substr($first, 0, 13),
            substr($second, 0, 13),
            'precondition: the clock is frozen, so the 48-bit prefix is shared',
        );

        self::assertNotSame(
            $groups[4],
            explode('-', $second)[4],
            'The last group must be random. If it is constant at a frozen clock it is echoing the timestamp, '
            . 'and 48 random bits never reach the output.',
        );

        // And it must not simply BE the prefix, which is the specific mutation: offset 20 → 0.
        self::assertNotSame(
            $groups[0] . $groups[1],
            $groups[4],
            'The last group repeats the first 12 hex characters, so the groups overlap rather than partition.',
        );
    }
}
