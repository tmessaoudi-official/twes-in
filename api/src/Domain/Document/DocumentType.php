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
 * The document types that share one lifecycle, one numbering machinery and one template engine.
 *
 * Generic from the start, which is a ruling rather than a preference: `pricing-and-documents.plan.md` states
 * the numbering is "the *same generic numbering machinery* rather than a delivery-note-specific one", and
 * Wave 2's stated theme is that anything true of Invoice must be true of Quote and Credit or explicitly not.
 * A lifecycle written for invoices first is what makes Wave 2 a rewrite instead of an addition.
 *
 * **Sequences are per type**, so invoice `0000041` and delivery note `0000041` both legitimately exist — see
 * {@see DocumentNumber} for why that ambiguity is made unrepresentable rather than merely documented.
 */
enum DocumentType: string
{
    // BACKED, because these values reach the wire and the database. `DocumentNumber::toString()` is documented
    // as being for "a log line, a search result, an API payload", and a non-backed enum would put a PHP CASE
    // NAME there — making a rename of a PHP identifier a breaking API change, which CLAUDE.md § "The API
    // contract is ours to design" says must never be incidental. Both enums are also persisted type/status
    // columns. A backed enum gives a stable identifier at no cost; snake_case because that is what the rest of
    // the contract will use.
    case Invoice = 'invoice';
    case Quote = 'quote';
    case Credit = 'credit';
    case DeliveryNote = 'delivery_note';
}
