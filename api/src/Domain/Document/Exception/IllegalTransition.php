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
     */
    private DocumentState $fromState;

    private DocumentState $toState;

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
        $refusal = new self(\sprintf(
            'A document cannot go from %s to %s. The permitted transitions are Draft to Issued, and Issued '
            . 'to Cancelled; a correction is a NEW document (cancel-and-reissue), never an edit, because a '
            . 'client may already hold the PDF of the document being corrected.',
            $from->value,
            $to->value,
        ));
        $refusal->fromState = $from;
        $refusal->toState = $to;

        return $refusal;
    }
}
