<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\ApiResource;

/**
 * One invoice line on the wire.
 *
 * **EVERY MONETARY AND QUANTITY FIELD IS A DECIMAL STRING, and that is the single most important contract decision
 * in this file.** JSON has one number type and it is a double: `0.1` is not representable, and a client that parses
 * `19.99` into a float has already lost the guarantee the whole `Money` type exists to provide. `CLAUDE.md` records
 * money-is-never-a-float as unfixable-later and names upstream's `double` columns as the worst defect in the
 * product twes-in learns from — sending a JSON number here would reintroduce it at the boundary, where all three
 * clients would then each round differently.
 *
 * The scale comes back from the database, so `quantity` may read `2.000000` for a stored `2`. That is the same
 * NUMBER at a different scale, which a decimal string expresses exactly and a float cannot. Clients compare
 * numerically, never by string — the mistake this project made in its own test and recorded.
 *
 * **`vat` IS PER LINE AND IS REQUIRED** (developer ruling). Under `PerRateGroup` the group's VAT is rounded once on
 * the summed base, so the rounded per-line figures do not add to it and a share must be ALLOCATED — largest
 * remainder, ties to the earliest line, summing EXACTLY to the group total. That rule is unfixable-later: change it
 * after documents are issued and re-rendering an old invoice produces different per-line figures, breaking the
 * byte-identical re-download guarantee for documents a client already holds.
 */
final readonly class InvoiceLineResource
{
    public function __construct(
        /** Contiguous from 0. `Invoice::withoutLine()` re-indexes, so a position is stable for the life of a read. */
        public int $position,
        /** Decimal string. `NUMERIC(21,6)` — six decimal places, because a quantity can legitimately be fractional. */
        public string $quantity,
        /** Net unit price, decimal string, in the document's currency. */
        public string $unitNet,
        /** VAT rate as a percentage, decimal string — `19`, `7`, `0`. Not a fraction: `19` means 19%, not 1900%. */
        public string $vatRate,
        /** `quantity × unitNet`, rounded half-up. Decimal string. */
        public string $net,
        /** This line's ALLOCATED share of its rate group's VAT. See the class docblock — this is not `net × rate`. */
        public string $vat,
    ) {}
}
