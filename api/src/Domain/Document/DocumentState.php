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

use Twes\Domain\Document\Exception\IllegalTransition;

/**
 * The document lifecycle: Draft -> Issued -> Cancelled, and nothing else.
 *
 * **No status is ever written by assignment.** CLAUDE.md § Architecture names that as a P0 for
 * `domain-correctness-reviewer`, and the reason is that a status set by assignment is how an illegal
 * transition enters a billing system — an issued invoice quietly becoming a draft again is an unnumbered,
 * mutable document that a client already holds a PDF of. So the only way forward is {@see self::transitionTo()},
 * which THROWS rather than returning false: a guard whose result a caller can discard is advice.
 *
 * Ruled in `pricing-and-documents.plan.md`:
 *  - **Draft** — unnumbered, mutable, PDF is preview-only and nothing is stored.
 *  - **Issued** — numbered from its type's own sequence, **immutable**, PDF rendered once and stored so a
 *    re-download is byte-identical. Re-rendering could differ if a template, logo or address changed since,
 *    which for a document a client already holds is a defect rather than an improvement.
 *  - **Cancelled** — terminal. Corrections go by cancel-and-reissue: the wrong document stays on file and a
 *    new one is issued, so the audit trail is complete and no number is ever silently reused.
 *
 * Deliberately NOT here: `Sent`, `Partial`, `Paid`, `Overdue`. Payments are Wave 3 and the transitions
 * between payment states are not ruled anywhere yet, so adding them would mean inventing a tax-document
 * lifecycle under time pressure. The enum is closed and the exhaustive test over its cartesian product fails
 * the day a case is added without a rule — which is the point at which the ruling gets made.
 */
enum DocumentState: string
{
    // BACKED, for the same reason as DocumentType: this is a persisted status column and a wire value, so a
    // rename of the PHP identifier must not be a breaking change.
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';

    /**
     * Whether the document's figures and lines may still change.
     *
     * Exactly one state says yes. An issued document may be in a client's hands; a cancelled one is an audit
     * record. Both would break the byte-identical-re-download guarantee if they could be edited.
     */
    public function isMutable(): bool
    {
        return self::Draft === $this;
    }

    /**
     * Whether the document carries a number from its type's sequence.
     *
     * A **cancelled** document still does, and that is the load-bearing part: it stays on file precisely so
     * its number is never reused, which is what makes the sequence auditable.
     */
    public function requiresNumber(): bool
    {
        return self::Draft !== $this;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ([$this, $target]) {
            [self::Draft, self::Issued], [self::Issued, self::Cancelled] => true,
            default => false,
        };
    }

    /**
     * The only way to reach another state.
     *
     * @throws IllegalTransition if the transition is not one of the two ruled ones
     */
    public function transitionTo(self $target): self
    {
        if (!$this->canTransitionTo($target)) {
            throw IllegalTransition::between($this->value, $target->value);
        }

        return $target;
    }
}
