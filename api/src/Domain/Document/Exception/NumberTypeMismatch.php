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

use Twes\Domain\Document\DocumentNumber;
use Twes\Domain\Document\DocumentType;

/**
 * A document was handed a number belonging to a DIFFERENT type — our fault, not the client's, so a 500.
 *
 * **A `\LogicException` and not a `\DomainException`, which is the whole reason this class exists.** No API we
 * would ever build lets a client choose a document number, so the only way to reach this is for our own
 * application layer to allocate from the wrong sequence — see
 * {@see \Twes\Domain\Document\DocumentNumberAllocator}. Until this type existed, `Invoice::issue()` raised bare
 * `\DomainException` for both this fault and the user-fixable emptiness case, leaving an HTTP layer to
 * distinguish a 500 from a 422 by matching message text.
 *
 * Not user-facing, so no translation key of its own: it maps to `error.internal` like every other fault, and
 * the detail belongs in the log rather than in a response.
 */
final class NumberTypeMismatch extends \LogicException
{
    public static function between(DocumentType $expected, DocumentNumber $given): self
    {
        return new self(\sprintf(
            'A %s cannot be issued with a %s number (%s). Sequences are per document type, so the digits '
            . 'alone are ambiguous — that is why a DocumentNumber carries its type — and this means the '
            . 'number was allocated from the wrong sequence.',
            $expected->value,
            $given->type()->value,
            $given->toString(),
        ));
    }
}
