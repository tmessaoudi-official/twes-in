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
 * How a document's sequence number is rendered — a configurable zero-padded pattern, and nothing else.
 *
 * Developer ruling, 2026-07-29: **the number carries no type marker.** No `INV-`, no `DN-`. It is a plain
 * zero-padded number from a configurable pattern, identical machinery for invoices, quotes, credits and
 * delivery notes. The document's title and template carry its identity; the number does not.
 *
 * A separate value object rather than an `int $width` parameter, because the pattern is **per-tenant
 * configuration**: it is chosen once and applied to every document of a type forever, and a number that
 * changes width mid-sequence is a number two documents can share once padding is stripped.
 */
final readonly class NumberPattern
{
    private function __construct(private int $width) {}

    /**
     * @param int $width total digits, zero-padded on the left
     *
     * @throws \InvalidArgumentException if the width is not positive
     */
    public static function padded(int $width): self
    {
        // `< 1`, not `< 0`: a width of zero renders every number unpadded, which is a legal-looking sequence
        // that silently drops the configuration. Width 1 is the honest minimum and is accepted.
        if ($width < 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A number pattern width must be at least 1, got %d. A width of zero would render every '
                . 'document number unpadded while appearing to be configured.',
                $width,
            ));
        }

        return new self($width);
    }

    public function width(): int
    {
        return $this->width;
    }

    /**
     * The rendered number. GROWS rather than truncating when the sequence outruns the pattern.
     *
     * Truncating would produce a DUPLICATE number, and for a numbered legal document that is the worst
     * outcome available: two invoices with one identity, and a tax authority that cannot tell them apart.
     * Growing breaks a column width at worst. `str_pad` does not truncate, which is why it is used rather
     * than `sprintf('%0*d')` — but the behaviour is asserted rather than assumed, because it is the whole
     * reason this method is not a one-liner at the call site.
     */
    public function format(int $sequence): string
    {
        // GUARDED HERE TOO, not only in DocumentNumber. Round 13 pointed out that this method is public and
        // rendered `format(0)` as `"0000000"` and `format(-5)` as `"00000-5"` — the first being exactly the
        // string `DocumentNumber` refuses a sequence of zero to prevent ("what an uninitialised counter holds"),
        // and the second a legal-looking document number. A value object that refuses a state while a
        // collaborator will render it on request has not refused it.
        if ($sequence < 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Cannot format sequence %d: a document sequence starts at 1. Zero is what an uninitialised '
                . 'counter holds and would render as "%s", and a negative renders as a legal-looking number '
                . 'with a minus inside it.',
                $sequence,
                str_pad('0', $this->width, '0', \STR_PAD_LEFT),
            ));
        }

        return str_pad((string) $sequence, $this->width, '0', \STR_PAD_LEFT);
    }
}
