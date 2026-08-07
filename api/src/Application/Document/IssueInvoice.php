<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Application\Document;

/**
 * "Issue the draft invoice with this id." A command — data, no behaviour.
 *
 * One field, and a class rather than a bare `string` parameter on the handler for two reasons that are not
 * symmetry with {@see CreateInvoice}: the name says which of the several UUIDs in this system it is, and a command
 * type is what a Messenger transport would carry the day issuing becomes asynchronous.
 *
 * **NO NUMBER, NO PATTERN, NO DATE.** The number is allocated by {@see IssueInvoiceHandler} from the gapless
 * counter; accepting one from a caller would be accepting a legal document number from outside the sequence that
 * guarantees it is unique and unskipped, which is the one thing this domain will not do.
 */
final readonly class IssueInvoice
{
    /** The document's UUID, canonical lowercase. Validated by `DocumentIdentity`'s rule when it reaches a query. */
    public function __construct(public string $documentId) {}
}
