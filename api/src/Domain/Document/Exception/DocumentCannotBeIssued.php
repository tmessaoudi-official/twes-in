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

/**
 * The document is a valid draft but its CONTENT is not issuable yet — a **client** error, and a 422.
 *
 * A named type rather than the bare `\DomainException` this condition used to raise, for the reason round 13
 * already established once for `Rate::fromPercentage()`: `Invoice::issue()` raised the same class for a
 * user-fixable emptiness *and* for a programming fault ({@see NumberTypeMismatch}), so an HTTP layer could only
 * tell a 422 from a 500 by matching the message text — and a message is not an interface. Translating one into
 * the other is how a user gets a "something went wrong" page for a form they could have fixed, and how a real
 * fault gets returned to a client as though they had caused it.
 *
 * User-facing, so its message is keyed: `document.empty_cannot_be_issued`.
 */
final class DocumentCannotBeIssued extends \DomainException
{
    public static function becauseItHasNoLines(): self
    {
        return new self(
            'An invoice with no lines cannot be issued. Issuing consumes a number from a per-tenant sequence '
            . 'permanently — numbers are never reused and a cancelled document keeps its number on file — so '
            . 'this would burn a legal document number on an empty document. Add a line, or delete the draft.',
        );
    }
}
