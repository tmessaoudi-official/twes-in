<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Document;

use Twes\Domain\Document\Exception\SequenceContractViolated;

/**
 * Turns a raw counter from {@see DocumentNumberSequence} into a {@see DocumentNumber}.
 *
 * A thin domain service rather than a static helper or a method on `DocumentNumber`, for one reason worth
 * stating: it has a **collaborator**, and a value object that reaches out to a port stops being a value object.
 * `DocumentNumber` must stay constructible from three plain values so it can be rehydrated from a database row
 * without a sequence anywhere in sight.
 *
 * **CALL THIS AT ISSUE, NEVER AT DRAFT.** {@see DocumentNumberSequence::allocateNext()} consumes a number
 * whether or not a document is ever persisted, so allocating when a draft is *created* means every abandoned
 * draft leaves a permanent hole in a legally gapless sequence. {@see Invoice} enforces the timing structurally
 * rather than by convention — `Invoice::draft()` takes no number and `number()` returns null until
 * `Invoice::issue()` is given one — and this class is the only thing that should ever be feeding it.
 *
 * **It does no arithmetic and holds no state.** The counter's value, its gaplessness and its serialisation are
 * all the adapter's, because all three need a row lock. What is here is the one check the domain can make
 * without a database: that the value it was handed is one a document number may legally be built from.
 */
final readonly class DocumentNumberAllocator
{
    public function __construct(private DocumentNumberSequence $sequence) {}

    /**
     * Consume the next number for this type under the currently bound tenant.
     *
     * The pattern is the CALLER's, not this class's, and it is not remembered: it is per-tenant, per-type
     * configuration, and a default here would be this class quietly deciding how a legal document is numbered.
     * It is also deliberately *not* part of {@see DocumentNumber::equals()} — a tenant widening its padding
     * must not split one document's identity in two.
     *
     * @throws SequenceContractViolated if the adapter breaks its own contract
     * @throws \RuntimeException if the counter cannot be advanced at all
     */
    public function allocate(DocumentType $type, NumberPattern $pattern): DocumentNumber
    {
        $counter = $this->sequence->allocateNext($type);

        // CHECKED HERE even though `DocumentNumber` refuses a non-positive sequence too, and the duplication is
        // the point: that constructor's message is addressed to whoever built the number ("what an
        // uninitialised counter holds"), which is not who is at fault. A counter row seeded at 0 or an
        // `ON CONFLICT` returning the pre-existing value is an ADAPTER fault, and the message has to say so and
        // name the class — otherwise the stack trace points at the domain and the search starts in the wrong
        // layer. This repo has recorded four times that a guard whose message misattributes the fault costs a
        // reader more than a missing guard.
        if ($counter < 1) {
            throw SequenceContractViolated::becauseTheCounterWasNotPositive($this->sequence, $type, $counter);
        }

        return new DocumentNumber($type, $pattern, $counter);
    }
}
