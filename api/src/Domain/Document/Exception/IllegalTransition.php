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

use Twes\Domain\Document\DocumentState;

/**
 * A state change that the document lifecycle does not permit.
 *
 * A dedicated type rather than `\LogicException`, because this is the one exception in the lifecycle that an
 * HTTP layer must translate into a 409 rather than a 500: attempting to issue an already-issued invoice is a
 * *client* error and a plausible double-click, not a programming fault.
 */
final class IllegalTransition extends \DomainException
{
    /**
     * **The two states are carried STRUCTURALLY** — round 16 found the `{from}`/`{to}` ruling unimplementable
     * because this factory took `string`, so the enum was lost at the throw site and an HTTP layer had nothing to
     * resolve `document.state.*` from. A rule with no way to be obeyed is not a rule.
     *
     * **PRIVATE, and promoted, so the payload cannot be absent.** Round 17 found the states assigned
     * *after* construction by {@see self::between()}, which left the PUBLIC constructor inherited from
     * `\DomainException` as a second way in — one that produced an instance whose accessors raise
     * `Error: Typed property ... must not be accessed before initialization`. That fatal lands in the HTTP layer
     * this payload exists to serve, replacing the 409 the state machine meant to raise with a 500. Promotion
     * makes the assignment the language's rather than the factory's, so there is no order of statements in which
     * a reachable instance is incomplete.
     *
     * @param string $message built by the factory, because it interpolates both states
     */
    private function __construct(
        private DocumentState $fromState,
        private DocumentState $toState,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function from(): DocumentState
    {
        return $this->fromState;
    }

    public function to(): DocumentState
    {
        return $this->toState;
    }

    public static function between(DocumentState $from, DocumentState $to): self
    {
        return new self($from, $to, \sprintf(
            'A document cannot go from %s to %s. The permitted transitions are Draft to Issued, and Issued '
            . 'to Cancelled; a correction is a NEW document (cancel-and-reissue), never an edit, because a '
            . 'client may already hold the PDF of the document being corrected.',
            $from->value,
            $to->value,
        ));
    }
}
