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
use Symfony\Component\Uid\Exception\InvalidArgumentException as SymfonyInvalidArgumentException;
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
        // Same millisecond, so only the random field separates them — 64 bits under `symfony/uid`, and within
        // one millisecond a 24-bit increment of them rather than a fresh draw. If they collided, the randomness
        // is not being applied and every row created in one millisecond would clash.
        $generator = new UuidV7Generator(FrozenClock::at('2026-07-29T12:00:00+00:00'));

        $identifiers = [];

        for ($i = 0; $i < 1000; ++$i) {
            $identifiers[] = $generator->nextIdentifier();
        }

        self::assertCount(1000, array_unique($identifiers));
    }

    /**
     * SAME MILLISECOND, STILL ASCENDING — the property the hand-written implementation did not have.
     *
     * This class's opening paragraph gives sortability as the entire reason for choosing v7 over v4: *"new
     * identifiers sort after old ones … inserts append to the right-hand edge of the index instead of landing
     * at random depths."* The previous implementation called `random_bytes(10)` afresh per call, so within one
     * millisecond the 74 random bits were independent draws and the order was a coin toss — half the pairs
     * inverted. The test above could not see it: distinctness and ordering are different properties, and 1000
     * distinct values say nothing about their sequence.
     *
     * `symfony/uid` re-randomises only when the millisecond changes and otherwise increments the random field,
     * so the ordering holds inside a millisecond as well as across them. Two documents created in one request
     * share a millisecond routinely, so this is the ordinary case rather than an exotic one.
     *
     * **THE PROPERTY IS CONDITIONAL, and it was stated unconditionally for a commit** (round 3 filed it). The
     * generator's state is `static` on `UuidV7`, and because this adapter always passes an explicit time,
     * `generate()` re-randomises whenever `$time !== self::$time` — on ANY difference, not only a forward one. So
     * a single `nextIdentifier()` at another millisecond ANYWHERE IN THE PROCESS resets the counter mid-sequence:
     * two generators with clocks one second apart, alternating, give **99 of 199 non-ascending pairs — every
     * single B-to-A pair, deterministically**, because the timestamp itself alternates downward. (This said "98 of
     * 199 … the same rate as the defect", which was wrong twice: 98 came from a DIFFERENT experiment — one
     * generator's own sequence with a foreign call interleaved, which really is a coin toss and really does vary
     * run to run — and attaching a rate to the deterministic case invites re-measuring something that cannot
     * move. Round 4 filed it.) Under `SystemClock` it holds, because real time only moves forward — that is the
     * production path, pinned by
     * {@see self::testIdentifiersFromTheSystemClockAreMonotonic()}. The multi-clock case is a REPLAY: a data
     * migration or an event stream pinning "now" to a historical moment, which is exactly what `FrozenClock`
     * exists for. Nothing consumes this generator yet; when a replay path is built it needs its own ordering
     * guarantee rather than this one.
     *
     * Asserted as STRICT ascent over the canonical string form, because that is the form the column stores and
     * `ORDER BY` compares. A `sort()`-and-compare would pass on a reversed list.
     */
    public function testIdentifiersFromOneFrozenMillisecondAreMonotonic(): void
    {
        $generator = new UuidV7Generator(FrozenClock::at('2026-07-29T12:00:00+00:00'));

        $identifiers = [];

        for ($i = 0; $i < 200; ++$i) {
            $identifiers[] = $generator->nextIdentifier();
        }

        $inversions = [];

        for ($i = 1; $i < \count($identifiers); ++$i) {
            if (strcmp($identifiers[$i], $identifiers[$i - 1]) <= 0) {
                $inversions[] = \sprintf('%d: %s <= %s', $i, $identifiers[$i], $identifiers[$i - 1]);
            }
        }

        self::assertSame(
            [],
            $inversions,
            \sprintf(
                "%d of %d consecutive identifiers minted in ONE millisecond did not ascend, so the sortability "
                . "this class exists for does not hold within a millisecond:\n  %s",
                \count($inversions),
                \count($identifiers) - 1,
                implode("\n  ", \array_slice($inversions, 0, 5)),
            ),
        );
    }

    /**
     * THE PRODUCTION PATH, asserted separately from the frozen-clock one because it holds for a different reason.
     *
     * Under `SystemClock` monotonicity is unconditional: real time only moves forward, so `generate()`'s
     * re-randomise branch can only ever fire on a NEW millisecond, and within a millisecond the counter runs.
     * The frozen-clock case above holds only while no other clock interleaves — see its docblock — so pinning
     * both is what distinguishes "the guarantee we ship" from "the guarantee under a test fixture".
     */
    public function testIdentifiersFromTheSystemClockAreMonotonic(): void
    {
        $generator = new UuidV7Generator(new SystemClock());

        $identifiers = [];

        for ($i = 0; $i < 2000; ++$i) {
            $identifiers[] = $generator->nextIdentifier();
        }

        $inversions = 0;

        for ($i = 1; $i < \count($identifiers); ++$i) {
            if (strcmp($identifiers[$i], $identifiers[$i - 1]) <= 0) {
                ++$inversions;
            }
        }

        self::assertSame(0, $inversions, 'wall-clock identifiers must ascend without exception');
    }

    /**
     * **THE RANDOM FIELD MUST NOT BE A SEQUENTIAL COUNTER — the one property that had no test at all.**
     *
     * A certification round changed `symfony/uid`'s same-millisecond increment from `1 + (24-bit random)` to a
     * constant `+1` and **every case in this file passed, exit 0**, while the generator emitted
     * `…ceb7, …ceb8, …ceb9, …ceba` — literally the `/invoices/1234` enumeration the class docblock cites as the
     * reason for not using a counter. Well-formedness, the version nibble, the variant, the clock prefix,
     * 1000-distinct and even strict ascent are ALL satisfied by a perfect counter. Distinctness and ordering say
     * nothing about unpredictability, which is why this case is separate rather than an extra assertion on one of
     * those.
     *
     * Note what this does NOT claim, since the class docblock's own overreach is what round 3 filed: consecutive
     * identifiers are correlated BY DESIGN here — the delta is bounded by 2^24 — so this asserts the increment is
     * a wide random jump rather than that an identifier is unguessable. It is not. Guessability is not a control
     * in this project; row-level security is.
     *
     * Measured on the trailing 48-bit group, modulo 2^48 to absorb a carry. Deterministic in effect rather than
     * statistical: under a constant increment EVERY delta is 1, so a median over 2^16 cannot occur — the delta is
     * uniform on [1, 2^24], making the real median ~2^23 and a false failure vanishingly unlikely.
     */
    public function testTheRandomFieldIsNotMerelyASequentialCounter(): void
    {
        $generator = new UuidV7Generator(FrozenClock::at('2026-07-29T12:00:00+00:00'));

        $tail = static fn(string $id): int => (int) hexdec(substr($id, -12));

        $deltas = [];
        $previous = $tail($generator->nextIdentifier());

        // A THOUSAND, not two hundred, and the number is load-bearing rather than generous. The distinct-count
        // assertion below is a BIRTHDAY test on the increment's space, and its power comes from the sample size:
        // over 2^24 a thousand draws expect ~0.03 collisions, while over a 2^12 space they expect ~94. At 200
        // draws a 12-bit increment still yields ~195 distinct values and slipped past — which is exactly the
        // mutant round 4 constructed (a large constant offset plus 12 random bits, keeping the median high).
        for ($i = 0; $i < 1000; ++$i) {
            $current = $tail($generator->nextIdentifier());
            $deltas[] = ($current - $previous) & (2 ** 48 - 1);
            $previous = $current;
        }

        sort($deltas);
        $median = $deltas[intdiv(\count($deltas), 2)];

        self::assertGreaterThan(
            2 ** 16,
            $median,
            \sprintf(
                'the median same-millisecond increment was %d. A small constant means the random field is a '
                . 'COUNTER, so an attacker holding one identifier knows the next — and every other case in this '
                . 'file passes on a perfect counter.',
                $median,
            ),
        );

        // AND NOT ALL EQUAL, which catches a constant increment of any size — a large fixed step would pass the
        // median assertion above while being exactly as predictable as `+1`.
        self::assertGreaterThan(
            995,
            \count(array_unique($deltas)),
            \sprintf(
                'only %d of %d increments were distinct. Over the full 24-bit space a thousand draws expect ~0.03 '
                . 'collisions, so a shortfall here means the increment comes from a much smaller space — which is '
                . 'guessable even when the median stays large, because a constant offset raises the median '
                . 'without adding one bit of entropy.',
                \count(array_unique($deltas)),
                \count($deltas),
            ),
        );
    }

    /**
     * A THIRD-PARTY EXCEPTION MUST NOT ESCAPE A DOMAIN PORT — and the refusal itself is the improvement.
     *
     * Both arms are asserted: the class, because `Symfony\Component\Uid\Exception\InvalidArgumentException`
     * reaching a caller would put an `Infrastructure/` dependency on a `Domain/` contract through a catch clause,
     * where no gate can see it; and the PREVIOUS exception too, so the translation cannot be "fixed" back by
     * widening the catch. The hand-written layout returned a well-formed identifier carrying a wrong instant here
     * — an id that sorts into the wrong decade forever — so failing is the correct behaviour, not a regression.
     */
    public function testAnInstantOutsideTheRepresentableRangeIsRefusedAsADomainFault(): void
    {
        $generator = new UuidV7Generator(FrozenClock::at('1960-01-01T00:00:00+00:00'));

        try {
            $generator->nextIdentifier();
            self::fail('a pre-epoch instant has no v7 encoding and must be refused');
        } catch (\LogicException $caught) {
            self::assertNotInstanceOf(SymfonyInvalidArgumentException::class, $caught);
            self::assertInstanceOf(SymfonyInvalidArgumentException::class, $caught->getPrevious());
            self::assertStringContainsString('48 UNSIGNED bits', $caught->getMessage());
        }
    }

    /**
     * **AND THE PER-MILLISECOND BASE DRAW MUST COME FROM A CSPRNG, WHICH THE CASE ABOVE CANNOT SEE.**
     *
     * `testTheRandomFieldIsNotMerelyASequentialCounter()` measures deltas WITHIN one frozen millisecond, so it is
     * blind to the base being a constant. Round 4 filed three mutants that pass it: cutting the increment's entropy
     * from 24 bits to 12 (a sibling guessable in 4096 tries), replacing `random_bytes(8)` at the re-randomise site
     * with zero bytes (every identifier after the first becomes a pure function of the clock —
     * `019f…-7000-8000-000000000000`), and removing `random_bytes` entirely so **two separate processes emit
     * byte-identical identifiers**. That last one is worse than guessability: it is a primary-key collision between
     * two workers.
     *
     * The property that catches all three is CROSS-PROCESS DISAGREEMENT: two independent processes handed the SAME
     * frozen instant must produce different identifiers, because the only thing that can differ between them is the
     * per-process seed. A deterministic generator cannot satisfy it and no amount of in-process cleverness fakes it.
     *
     * Run as real subprocesses, since the state is `static` and one process cannot un-seed itself. `PHP_BINARY`
     * rather than a literal `php`, so this works wherever the suite does.
     */
    public function testTwoProcessesAtOneFrozenInstantDisagree(): void
    {
        // THE CLOCK ADVANCES between the third and fourth identifier, and that is not incidental. `symfony/uid`
        // seeds `self::$seed` with `random_bytes(16)` on the FIRST randomise and uses `random_bytes(8)` only when
        // the millisecond CHANGES — so a frozen clock never reaches the second draw at all. A mutant zeroing it
        // therefore survived a frozen-clock-only version of this case while making every identifier after the
        // first a pure function of the clock. Both draws are now observed.
        $script = <<<'PHP'
            require $argv[1];
            $clock = Twes\Infrastructure\Shared\FrozenClock::at('2026-07-29T12:00:00+00:00');
            $g = new Twes\Infrastructure\Shared\UuidV7Generator($clock);
            echo $g->nextIdentifier(), "\n", $g->nextIdentifier(), "\n";
            $clock->advanceBy('PT1S');
            echo $g->nextIdentifier(), "\n";
            $clock->advanceBy('PT1S');
            echo $g->nextIdentifier(), "\n";
            PHP;

        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $emitted = [];

        for ($process = 0; $process < 2; ++$process) {
            $output = shell_exec(\sprintf(
                '%s -r %s %s',
                escapeshellarg(\PHP_BINARY),
                escapeshellarg($script),
                escapeshellarg($autoload),
            ));

            self::assertIsString($output, 'the subprocess produced no output');
            $emitted[] = array_values(array_filter(explode("\n", trim($output))));
        }

        self::assertCount(4, $emitted[0], 'each process must emit four identifiers');
        self::assertCount(4, $emitted[1], 'each process must emit four identifiers');

        // EVERY POSITION, because the two random draws are reached at different ones: index 0-1 come from the
        // first seed, index 2-3 from a re-randomise on a changed millisecond. A mutant can kill either alone.
        foreach ([0, 1, 2, 3] as $position) {
            self::assertNotSame(
                $emitted[0][$position],
                $emitted[1][$position],
                \sprintf(
                    "two PROCESSES driven by the SAME clock produced the SAME identifier at position %d (%s), so "
                    . "the per-process randomness is not random — every id is a pure function of the clock. That "
                    . "is a primary-key collision between workers as well as full enumerability, and the "
                    . "same-millisecond delta test cannot see it because it only measures the INCREMENT. "
                    . "Positions 0-1 come from the initial 16-byte seed, 2-3 from the 8-byte re-randomise on a "
                    . "changed millisecond — check which one this is.",
                    $position,
                    $emitted[0][$position],
                ),
            );
        }
    }

    public function testTheSystemClockIsUtc(): void
    {
        self::assertSame('UTC', new SystemClock()->now()->getTimezone()->getName());
    }

    /**
     * The five hyphen-separated groups must PARTITION the 32 hex characters, not overlap.
     *
     * **WHAT THIS GUARDS CHANGED WHEN `symfony/uid` WAS ADOPTED, and this docblock claimed the old thing for a
     * commit.** It described a mutation of a `substr($hex, 20, 12)` chain that no longer exists — the whole
     * `sprintf`/`substr` block was deleted — and quoted the superseded 74- and 26-bit figures. What the case
     * still asserts is a real property of the OUTPUT rather than of our slicing: at a frozen millisecond the
     * timestamp prefix is identical, so any group that merely echoes it is caught by a single inequality, and
     * group 4 must vary between two ids. Under `symfony/uid` that holds by construction (`rand[2]` is
     * incremented every call, so it cannot repeat), which makes this a guard on the DEPENDENCY's layout now.
     * Kept for exactly that reason: a dependency bump is the thing most likely to change it silently.
     *
     * It is NOT the entropy guard it used to claim to be — see
     * {@see self::testTheRandomFieldIsNotMerelyASequentialCounter()}, which is, and which exists because a
     * certification round reduced the increment to a constant `+1` and every case in this file still passed.
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
