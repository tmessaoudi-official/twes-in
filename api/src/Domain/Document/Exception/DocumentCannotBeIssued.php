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
 * User-facing, so each message is keyed: `document.empty_cannot_be_issued` and
 * `document.client_required_to_issue`.
 */
final class DocumentCannotBeIssued extends \DomainException
{
    /**
     * **A DRAFT MAY HAVE NO CLIENT; AN ISSUED INVOICE MAY NOT** (ruled 2026-08-22, and argued in
     * `build-waves.plan.md`'s Decisions Log because the plans were silent).
     *
     * EN 16931 makes the buyer name (BT-44) MANDATORY on an invoice, and an invoice addressed to nobody is not
     * a document a tax authority accepts. But a draft is something under construction: the same reasoning that
     * lets a draft hold no LINES lets it hold no client, and forcing one at creation would mean a user cannot
     * start typing an invoice before deciding who it is for.
     *
     * So the requirement attaches at the TRANSITION rather than at the type, exactly as the line requirement
     * does — which is what makes the two guards siblings rather than a special case.
     */
    public static function becauseItHasNoClient(): self
    {
        return new self(
            'An invoice with no client cannot be issued. EN 16931 makes the buyer mandatory (BT-44), and an '
            . 'issued invoice addressed to nobody is not a document a tax authority accepts — while a DRAFT may '
            . 'legitimately have none, because deciding who an invoice is for can come after typing what is on '
            . 'it. Choose a client, or delete the draft.',
        );
    }

    public static function becauseItHasNoLines(): self
    {
        return new self(
            'An invoice with no lines cannot be issued. Issuing consumes a number from a per-tenant sequence '
            . 'permanently — numbers are never reused and a cancelled document keeps its number on file — so '
            . 'this would burn a legal document number on an empty document. Add a line, or delete the draft.',
        );
    }
}
