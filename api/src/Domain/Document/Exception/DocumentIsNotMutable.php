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
 * An attempt to change a document that is no longer a draft.
 *
 * Distinct from {@see IllegalTransition}, and the split matters at the HTTP boundary: an illegal *transition*
 * is a state-machine error, while this is an edit to a frozen document — a 409 either way, but the remedy
 * differs. An issued document is corrected by **cancel-and-reissue**, never by editing, because the client
 * may already hold the PDF; a cancelled one is an audit record and is never touched again.
 *
 * The message names the OPERATION as well as the state, because "document is not mutable" in a log tells a
 * reader nothing about which call to move.
 */
final class DocumentIsNotMutable extends \DomainException
{
    /**
     * **The state is carried STRUCTURALLY, not only interpolated** — round 16 found the `{state}` ruling had no
     * implementation path. `document.state.draft`/`issued`/`cancelled` exist so a translated label replaces the
     * backed value, and neither this class nor {@see IllegalTransition} exposed the state, so an HTTP layer
     * catching them had nothing to resolve the label FROM. A rule with no way to be obeyed is not a rule.
     */
    private DocumentState $refusedState;

    public function state(): DocumentState
    {
        return $this->refusedState;
    }

    public static function forOperation(string $operation, DocumentState $state): self
    {
        $refusal = new self(\sprintf(
            'Cannot %s: this document is %s, and only a Draft is mutable. %s',
            $operation,
            $state->value,
            DocumentState::Issued === $state
                ? 'An issued document is corrected by cancel-and-reissue — cancel it and issue a new one with '
                    . 'the correct figures — because the client may already hold its PDF and a re-render could '
                    . 'differ if a template, logo or address changed since.'
                : 'A cancelled document is an audit record of what was issued; it is never edited, and the '
                    . 'correction is a separate document.',
        ));
        $refusal->refusedState = $state;

        return $refusal;
    }
}
