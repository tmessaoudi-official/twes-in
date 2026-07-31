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

/**
 * A document's identity: its TYPE and its sequence number, inseparably.
 *
 * **This class exists to make a stated hazard unrepresentable rather than merely documented.**
 * `pricing-and-documents.plan.md` rules that sequences are per type, then names the consequence:
 *
 * > *because sequences are per-type, invoice `0000041` and delivery note `0000041` can both exist. That is
 * > normal and correct on a printed document, where the title disambiguates — but any internal reference,
 * > search result or API payload must name the document **type alongside the number**, never the number
 * > alone.*
 *
 * A rule written that way depends on every future caller remembering it, and this repository has recorded
 * four times that a control enforced only by memory is not a control. So there is no way to construct a bare
 * number here: the type travels with it, {@see self::equals()} compares both, and {@see self::toString()}
 * names both. Two documents sharing digits across types are not equal, which is what stops a delivery note
 * being paid against an invoice.
 */
final readonly class DocumentNumber
{
    /**
     * @param int $sequence 1-based position in this type's per-tenant sequence
     *
     * @throws \InvalidArgumentException if the sequence is not positive
     */
    public function __construct(
        private DocumentType $type,
        private NumberPattern $pattern,
        private int $sequence,
    ) {
        // ZERO IS REFUSED, and it is the value that matters: zero is what an uninitialised counter holds, so
        // accepting it means the first document of a tenant's life is silently numbered `0000000` — and nobody
        // notices until an accountant asks why the sequence starts at zero.
        if ($sequence < 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A document sequence number starts at 1, got %d. Zero is what an uninitialised counter '
                . 'holds, so accepting it would number a tenant\'s first document 0.',
                $sequence,
            ));
        }
    }

    public function type(): DocumentType
    {
        return $this->type;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    /** The rendered number alone — for the printed document, where the title disambiguates. */
    public function number(): string
    {
        return $this->pattern->format($this->sequence);
    }

    /**
     * The number WITH its type — for a log line, a search result, an API payload or an internal reference.
     *
     * This is the form the plan requires everywhere except the printed document itself. Deliberately not
     * `__toString()`: an implicit string conversion is exactly how the bare number ends up in a payload.
     */
    public function toString(): string
    {
        return $this->type->name . ' ' . $this->number();
    }

    /**
     * Equal only when the TYPE and the sequence both match.
     *
     * Comparing digits alone is how a delivery note gets paid against an invoice. The pattern is deliberately
     * NOT compared: `0000041` and `041` are the same document rendered under two configurations, and a
     * tenant changing its pattern must not split one document's identity in two.
     */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->sequence === $other->sequence;
    }
}
