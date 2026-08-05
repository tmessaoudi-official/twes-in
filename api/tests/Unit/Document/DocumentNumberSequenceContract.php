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
 * **The port states FIVE guarantees and this class asserts FOUR. The fifth is named here rather than dropped.**
 * Round 15 found the count wrong in both this docblock and the plan, and the omitted one is **#5, Serialised** —
 * the guarantee `SELECT ... FOR UPDATE` exists to deliver, and the only one whose violation is *two invoices
 * sharing a number*, which the plan calls a worse outcome than a queued request. A
 * `PostgresDocumentNumberSequenceTest extends DocumentNumberSequenceContract` therefore satisfied "the contract"
 * with zero concurrency assertions and nothing disclosed the gap.
 *
 * It is not asserted here because the in-memory double is single-process and cannot violate it — there is no
 * concurrency to serialise. **It is owed by the adapter's own test**, and the guarantee-count test below fails if
 * this disclosure is ever deleted, so the gap cannot go quiet again.
 * What it will take: two connections, both allocating for one `(tenant, type)` inside overlapping transactions,
 * asserting the second BLOCKS until the first commits and then returns the next value — not the same one.
 *
 * The four asserted guarantees are gapless, starts at 1, independent per type, and never reused —
 * and a guarantee written only in a docblock is enforced by whoever remembers to read it. This repository has
 * recorded five separate times that a control enforced by memory is not a control, so the contract is a test
 * class instead: an adapter that does not extend this has not been shown to satisfy anything.
 *
 * When the Postgres adapter lands (Wave 1's persistence — no longer blocked; Doctrine, the migration and the
 * mapper have all landed, and only the repository itself is owed), it gets
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
    /**
     * **The contract DISCLOSES the guarantee it does not assert.** A guard against the gap going quiet again.
     *
     * Round 15 found this class claiming "four guarantees" while the port declares five, so #5 (Serialised) was
     * neither asserted nor mentioned — and an adapter extending this class would have looked fully certified.
     * This test reads the port's own docblock and requires the number of numbered guarantees there to match the
     * number this class accounts for, so ADDING a guarantee to the port without either asserting it here or
     * disclosing it fails rather than passing silently.
     */
    public function testTheContractDeclaresItsOwnUnassertedGuarantee(): void
    {
        $port = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Domain/Document/DocumentNumberSequence.php',
        );

        // The port numbers its guarantees `**1. `, `**2. ` … so counting them is exact rather than a keyword hunt.
        preg_match_all('/^\s*\*\s+\*\*(\d+)\. /m', $port, $matches);
        $declared = \count($matches[1]);

        self::assertSame(
            5,
            $declared,
            \sprintf(
                'The port declares %d numbered guarantees. This contract class asserts four and DISCLOSES the '
                . 'fifth (Serialised) as owed by the adapter. If a sixth was added, assert it here or amend '
                . 'this class\'s docblock and this count — silently widening the port leaves every adapter '
                . 'certified against a contract it does not meet.',
                $declared,
            ),
        );

        // And the disclosure must actually be present, not merely the count correct.
        //
        // **THE NEEDLE COMES FROM THE PORT, not from a literal in this file** — the first version of this
        // assertion hardcoded the name and compared it against `__FILE__`, so needle and haystack were the same
        // file and renaming the guarantee in both kept it green. A self-referential check cannot fail. Reading
        // the name out of the port means mutating either side breaks it: the port alone fails the count above,
        // this file alone fails the containment below.
        preg_match('/^\s*\*\s+\*\*5\. ([A-Za-z ]+?)\.\*\*/m', $port, $fifth);
        self::assertArrayHasKey(1, $fifth, 'The port must name its fifth guarantee so this can check for it.');

        self::assertStringContainsString(
            trim($fifth[1]),
            // THE CLASS DOCBLOCK ONLY, not the whole file — round 16 found the needle had moved to the port
            // while the HAYSTACK was still `__FILE__`, and this guard's own docblock and failure message both
            // contain the word, so deleting the disclosure paragraph left it green. Half a fix for a
            // self-referential check is still self-referential.
            self::disclosureParagraph(),
            \sprintf(
                'The port\'s guarantee "%s" is asserted by no case here, so this class must NAME it as owed by '
                . 'the adapter. Without that, an adapter extending this class looks fully certified against a '
                . 'contract it does not meet.',
                trim($fifth[1]),
            ),
        );
    }

    /**
     * The CLASS docblock alone — the only place a disclosure of an unasserted guarantee is addressed to an adapter
     * author. Deliberately excludes this file's test bodies and their messages, which is what made the containment
     * check pass while the disclosure was deleted.
     */
    private static function disclosureParagraph(): string
    {
        $docblock = new \ReflectionClass(self::class)->getDocComment();

        return false === $docblock ? '' : $docblock;
    }

}
