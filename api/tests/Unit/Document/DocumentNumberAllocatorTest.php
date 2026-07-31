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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Document\DocumentNumberAllocator;
use Twes\Domain\Document\DocumentNumberSequence;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\Exception\SequenceContractViolated;
use Twes\Domain\Document\NumberPattern;
use Twes\Tests\Support\InMemoryDocumentNumberSequence;

/**
 * The allocator that turns a raw counter into a {@see \Twes\Domain\Document\DocumentNumber}.
 *
 * Wave 1's scope line reads "numbering with per-tenant counters", and until now only the *rendering* half
 * existed: `DocumentNumber` and `NumberPattern` could format a sequence nobody allocated, and
 * `Invoice::issue()` took a finished number from its caller. Every test here covers behaviour that had no code.
 *
 * **Scoped to the allocator.** The counter's own guarantees — gapless, starts at 1, independent per type —
 * belong to the port and are asserted by {@see DocumentNumberSequenceContract}, not here. Asserting them
 * through the allocator would be testing the double, which is how a suite comes to certify its own fixture.
 */
#[CoversClass(DocumentNumberAllocator::class)]
final class DocumentNumberAllocatorTest extends TestCase
{
    /**
     * The counter is passed through to the number, and rendered through the caller's pattern.
     *
     * Two facts in one assertion on purpose: they are the whole of the happy path, and separating them would
     * suggest a pattern-less number is a meaningful intermediate state.
     */
    public function testTheCounterBecomesTheNumbersSequenceRenderedThroughThePattern(): void
    {
        $number = new DocumentNumberAllocator(new InMemoryDocumentNumberSequence())
            ->allocate(DocumentType::Invoice, NumberPattern::padded(7));

        self::assertSame(1, $number->sequence());
        self::assertSame('0000001', $number->number());
    }

    /**
     * The pattern is the CALLER's and is not remembered between calls.
     *
     * Per-tenant, per-type configuration, so a default inside the allocator would be it quietly deciding how a
     * legal document is numbered. Driving two different widths through one allocator proves it holds none.
     */
    public function testThePatternIsTheCallersAndIsNotRetained(): void
    {
        $allocator = new DocumentNumberAllocator(new InMemoryDocumentNumberSequence());

        $wide = $allocator->allocate(DocumentType::Invoice, NumberPattern::padded(7));
        $narrow = $allocator->allocate(DocumentType::Invoice, NumberPattern::padded(2));

        self::assertSame('0000001', $wide->number());
        self::assertSame('02', $narrow->number());
    }

    /**
     * EVERY type is allocatable, and the number carries the type it was asked for.
     *
     * Generated from the enum: `DocumentType` is a set, and CLAUDE.md's full-set coverage rule says a change
     * applying to a class of things must enumerate all of them. A hand-picked pair pins the fixture's instances
     * rather than the rule, which this repo has recorded as a live failure mode.
     */
    #[DataProvider('everyDocumentType')]
    public function testTheAllocatedNumberCarriesTheRequestedType(DocumentType $type): void
    {
        $number = new DocumentNumberAllocator(new InMemoryDocumentNumberSequence())
            ->allocate($type, NumberPattern::padded(3));

        self::assertSame($type, $number->type());
        self::assertSame($type->value . ' 001', $number->toString());
    }

    /** @return iterable<string, array{DocumentType}> */
    public static function everyDocumentType(): iterable
    {
        foreach (DocumentType::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    /**
     * An adapter returning a non-positive counter is REFUSED — and this is the allocator's own reason to exist.
     *
     * `DocumentNumber` refuses it too, with a message about uninitialised counters addressed to whoever built
     * the number. That is not who is at fault: a counter row seeded at 0, a wrong column default, or an
     * `INSERT ... ON CONFLICT` returning the pre-existing value are all ADAPTER faults, and the message has to
     * say so or the stack trace starts the search in the wrong layer.
     */
    #[DataProvider('nonPositiveCounters')]
    public function testAnAdapterReturningANonPositiveCounterIsRefused(int $counter): void
    {
        $allocator = new DocumentNumberAllocator(new InMemoryDocumentNumberSequence($counter));

        $this->expectException(SequenceContractViolated::class);
        $this->expectExceptionMessage('must return a positive counter');

        $allocator->allocate(DocumentType::Invoice, NumberPattern::padded(7));
    }

    /** @return iterable<string, array{int}> */
    public static function nonPositiveCounters(): iterable
    {
        yield 'zero — what an uninitialised counter row holds' => [0];
        yield 'negative — a decrementing or wrapped counter' => [-1];
        yield 'far negative' => [\PHP_INT_MIN];
    }

    /**
     * ONE IS ACCEPTED. The boundary in the other direction, without which `< 1` and `<= 1` are indistinguishable.
     *
     * A `<= 1` guard would refuse every tenant's very first document — the one case nobody exercises manually
     * before shipping, because it only happens once per tenant per type.
     */
    public function testACounterOfExactlyOneIsAccepted(): void
    {
        $number = new DocumentNumberAllocator(new InMemoryDocumentNumberSequence(1))
            ->allocate(DocumentType::Invoice, NumberPattern::padded(7));

        self::assertSame(1, $number->sequence());
    }

    /**
     * The refusal names the ADAPTER's class, because that is the file to open.
     *
     * With several adapters wired by configuration, "a sequence adapter returned 0" leaves the reader guessing.
     * Asserted separately from the refusal so that weakening the message to a generic string is a red test
     * rather than a silent regression — this repo has recorded twice that asserting only a non-zero exit or a
     * bare exception type makes a crash and a detection indistinguishable.
     */
    public function testTheRefusalNamesTheOffendingAdapterAndTheType(): void
    {
        $allocator = new DocumentNumberAllocator(new InMemoryDocumentNumberSequence(0));

        // CAUGHT rather than expected, because `expectExceptionMessage()` holds ONE expectation — calling it
        // twice silently replaces the first, so a two-call version of this test would assert only the second
        // string while appearing to assert both. That is the vacuous-assertion shape § Gotchas records.
        try {
            $allocator->allocate(DocumentType::DeliveryNote, NumberPattern::padded(7));

            self::fail('A counter of 0 must be refused.');
        } catch (SequenceContractViolated $violation) {
            self::assertStringContainsString(InMemoryDocumentNumberSequence::class, $violation->getMessage());
            self::assertStringContainsString('delivery_note', $violation->getMessage());
        }
    }
    /**
     * **The allocator FORWARDS the requested type to the port — asserted, because replacing it with a constant
     * left the suite green** (round 15).
     *
     * The mutant put permanent holes in BOTH legal sequences: invoice 1, quote 2, invoice 3, quote 4 instead of
     * invoice 1, quote 1, invoice 2, quote 2. A missing invoice number is what a tax authority reads as a
     * suppressed sale, so this is the most expensive thing the allocator can get wrong.
     *
     * It survived because `testTheAllocatedNumberCarriesTheRequestedType` checks the type stamped ON the
     * `DocumentNumber`, which the mutant still did correctly — and the per-type guarantee was explicitly scoped
     * OUT of this class as "the port's, asserted by DocumentNumberSequenceContract". But the FORWARDING is the
     * allocator's own behaviour, not the port's, and it fell in the gap between the two. The
     * "exemption inside a cross-check is where the drift hides" shape.
     *
     * A recording double rather than the in-memory one, because what is under test is the ARGUMENT passed, which
     * no return value reveals.
     */
    public function testTheRequestedTypeIsForwardedToTheSequence(): void
    {
        $recorder = new class implements DocumentNumberSequence {
            /** @var list<DocumentType> */
            public array $asked = [];

            public function allocateNext(DocumentType $type): int
            {
                $this->asked[] = $type;

                return \count($this->asked);
            }
        };

        $allocator = new DocumentNumberAllocator($recorder);
        $pattern = NumberPattern::padded(4);

        // EVERY type, generated from the enum — a hand-picked pair would let a mutant forwarding a constant that
        // happens to be one of them survive.
        foreach (DocumentType::cases() as $type) {
            $allocator->allocate($type, $pattern);
        }

        self::assertSame(
            DocumentType::cases(),
            $recorder->asked,
            'The allocator must ask the sequence for the type it was given. Forwarding a constant instead gaps '
            . 'every sequence permanently while the allocated numbers still LOOK right.',
        );
    }

}
