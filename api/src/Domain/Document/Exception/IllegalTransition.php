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
 * A state change that the document lifecycle does not permit.
 *
 * A dedicated type rather than `\LogicException`, because this is the one exception in the lifecycle that an
 * HTTP layer must translate into a 409 rather than a 500: attempting to issue an already-issued invoice is a
 * *client* error and a plausible double-click, not a programming fault.
 */
final class IllegalTransition extends \DomainException
{
    public static function between(string $from, string $to): self
    {
        return new self(\sprintf(
            'A document cannot go from %s to %s. The permitted transitions are Draft to Issued, and Issued '
            . 'to Cancelled; a correction is a NEW document (cancel-and-reissue), never an edit, because a '
            . 'client may already hold the PDF of the document being corrected.',
            $from,
            $to,
        ));
    }
}
