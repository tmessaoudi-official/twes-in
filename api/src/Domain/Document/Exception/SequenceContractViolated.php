<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Document\Exception;

use Twes\Domain\Document\DocumentNumberSequence;
use Twes\Domain\Document\DocumentType;

/**
 * A {@see DocumentNumberSequence} adapter returned a value its own contract forbids.
 *
 * **A `\LogicException` subclass on purpose, and the opposite call from {@see IllegalTransition}.** An illegal
 * transition is a *client* error — a plausible double-click — and becomes a 409. This is a programming or
 * configuration fault in our own adapter: a counter row seeded at 0, a wrong SQL default, an
 * `INSERT ... ON CONFLICT` returning the pre-existing value. It must surface as a 500 and reach an alert,
 * because the alternative is issuing legal documents from a counter nobody trusts.
 *
 * It names the offending adapter's class, because with several wired by configuration a message reading
 * "a sequence adapter" leaves the reader guessing which file to open.
 */
final class SequenceContractViolated extends \LogicException
{
    public static function becauseTheCounterWasNotPositive(
        DocumentNumberSequence $adapter,
        DocumentType $type,
        int $counter,
    ): self {
        return new self(\sprintf(
            '%s must return a positive counter and returned %d for %s. A document sequence starts at 1: zero '
            . 'is what an uninitialised counter row holds, so this is a seeding or default fault in the '
            . 'adapter rather than bad input, and issuing from it would number a tenant\'s first document 0.',
            $adapter::class,
            $counter,
            $type->value,
        ));
    }
}
