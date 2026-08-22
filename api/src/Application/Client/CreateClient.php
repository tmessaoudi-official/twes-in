<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Application\Client;

use Twes\Domain\Client\PostalAddress;

/**
 * Create a client for the calling tenant.
 *
 * **NO TENANT FIELD, and its absence is the invariant rather than an omission.** `CLAUDE.md` § Gotchas 2026-07-31
 * rules that tenancy is ambient CONTEXT and not data: a tenant on the command would be a second, forgeable answer
 * to a question the request already answers, and the reductio there applies here unchanged — if this command needs
 * one, so does every other, and a field every type needs is not a field. The adapter reads it from `TenantContext`
 * and refuses when none is bound.
 *
 * **NO ID FIELD.** Identity is the handler's decision, minted from `IdGenerator` — see
 * {@see \Twes\UI\Http\ApiResource\NewClientInput} for why a caller choosing a primary key is an existence oracle.
 *
 * **THE ADDRESS ARRIVES ALREADY PARSED and the contacts do not**, and the asymmetry is deliberate.
 * {@see PostalAddress} is constructible from the wire's own values, so the transport parses it and a refusal
 * becomes a 422 naming the field. A {@see \Twes\Domain\Client\Contact} cannot be built without an id the transport
 * is not allowed to choose, so it arrives as a {@see NewContact} and the handler completes it. The bounds on those
 * three fields are mirrored by validator constraints at the edge, which is what keeps a domain refusal in the
 * handler meaning OUR modelling error rather than the caller's — the same split
 * {@see \Twes\UI\Http\State\CreateInvoiceProcessor} documents.
 */
final readonly class CreateClient
{
    /**
     * @param list<NewContact> $contacts
     */
    public function __construct(
        public string $name,
        public ?string $taxIdentifier,
        public ?PostalAddress $address,
        public array $contacts,
    ) {}
}
