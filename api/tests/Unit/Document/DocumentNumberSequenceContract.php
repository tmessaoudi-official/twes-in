<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Document\DocumentNumberSequence;
use Twes\Domain\Document\DocumentType;

/**
 * **The executable form of {@see DocumentNumberSequence}'s contract. Every adapter MUST extend this.**
 *
 * The port's docblock states four guarantees — gapless, starts at 1, independent per type, never backwards —
 * and a guarantee written only in a docblock is enforced by whoever remembers to read it. This repository has
 * recorded five separate times that a control enforced by memory is not a control, so the contract is a test
 * class instead: an adapter that does not extend this has not been shown to satisfy anything.
 *
 * When the Postgres adapter lands (Wave 1's persistence, currently blocked on Composer egress), it gets
 * `PostgresDocumentNumberSequenceTest extends DocumentNumberSequenceContract` in the **integration** suite,
 * against a real row lock. The cases below do not change; only {@see self::sequence()} does. That is the point
 * — the same assertions run against the double and against the database, so "the fake behaves differently from
 * production" becomes a red test rather than a discovery.
 *
 * **What this class deliberately does NOT assert: uniqueness across processes.** No adapter can promise it, and
 * pretending otherwise here would make the composite unique constraint on `(company_id, type, number)` look
 * redundant. See the port's docblock.
 */
abstract class DocumentNumberSequenceContract extends TestCase
{
    /** The adapter under test. A fresh, empty one on every call. */
    abstract protected function sequence(): DocumentNumberSequence;

    /**
     * Guarantee 2: the first document of a tenant's life is number 1.
     *
     * Zero is what an uninitialised counter row holds, and both `DocumentNumber` and `NumberPattern` refuse it —
     * so an adapter that starts at 0 fails at the point of *use*, with a message about uninitialised counters
     * addressed to the wrong layer. Caught here instead.
     */
    #[DataProvider('everyDocumentType')]
    public function testTheFirstCounterOfEveryTypeIsOne(DocumentType $type): void
    {
        self::assertSame(1, $this->sequence()->allocateNext($type));
    }

    /**
     * **Guarantee 1, the load-bearing one: GAPLESS.**
     *
     * A missing number in an invoice sequence is what a tax authority reads as a suppressed sale, and France and
     * Tunisia both audit for it. Asserted as an exact list rather than "increases", because "increases" is also
     * true of `1, 2, 4` — which is the actual failure mode of the implementation the port forbids (`nextval`
     * does not roll back, so a failed issue leaves a permanent hole).
     */
    #[DataProvider('everyDocumentType')]
    public function testCountersAreConsecutiveWithNoGap(DocumentType $type): void
    {
        $sequence = $this->sequence();
        $allocated = [];

        for ($i = 0; $i < 10; ++$i) {
            $allocated[] = $sequence->allocateNext($type);
        }

        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $allocated);
    }

    /**
     * Guarantee 3: each type counts independently.
     *
     * Interleaved rather than run in blocks, because a single shared counter passes a blocked version of this
     * test whenever the types happen to be visited in order.
     */
    public function testCountersAreIndependentPerType(): void
    {
        $sequence = $this->sequence();
        $seen = [];

        // Three passes over every type, interleaved. Pass N must yield N for every type.
        for ($pass = 1; $pass <= 3; ++$pass) {
            foreach (DocumentType::cases() as $type) {
                $seen[$type->value][] = $sequence->allocateNext($type);
            }
        }

        foreach (DocumentType::cases() as $type) {
            self::assertSame(
                [1, 2, 3],
                $seen[$type->value],
                \sprintf('%s must have its own counter, not a share of a global one.', $type->value),
            );
        }
    }

    /**
     * Guarantee 4: a counter never goes backwards and never repeats a value it has issued.
     *
     * A cancelled document keeps its number forever, so nothing is ever recycled. Distinct from gaplessness:
     * `1, 2, 2, 3` is monotonic-ish and gapless by a careless reading, and duplicates a legal document number.
     */
    #[DataProvider('everyDocumentType')]
    public function testNoCounterValueIsEverIssuedTwice(DocumentType $type): void
    {
        $sequence = $this->sequence();
        $allocated = [];

        for ($i = 0; $i < 25; ++$i) {
            $allocated[] = $sequence->allocateNext($type);
        }

        // `array_values` around `array_unique`, because `array_unique` PRESERVES KEYS — so on a duplicate it
        // returns a gappy-keyed array that `assertSame` would report as unequal for the wrong reason, and the
        // failure message would send a reader looking at ordering instead of at the duplicate.
        self::assertSame(
            $allocated,
            array_values(array_unique($allocated)),
            'A counter value was issued twice — a duplicate legal document number.',
        );

        $sorted = $allocated;
        sort($sorted);
        self::assertSame($sorted, $allocated, 'A counter went backwards.');
    }

    /** @return iterable<string, array{DocumentType}> */
    public static function everyDocumentType(): iterable
    {
        // GENERATED from the enum rather than hand-picked. This repo has recorded that hand-picked cases pin the
        // fixture's instances rather than the rule, so adding a DocumentType adds its own cases here.
        foreach (DocumentType::cases() as $case) {
            yield $case->value => [$case];
        }
    }
}
