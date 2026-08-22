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

use Symfony\Component\Validator\Constraints as Assert;
use Twes\Domain\Client\Client;

/**
 * The body of `POST /api/clients`. **Input only** — the response is a {@see ClientResource}.
 *
 * ## What a client may decide, and what it may not
 *
 * **IT MAY NOT DECIDE THE ID**, its own or any contact's. Both are generated server-side from
 * {@see \Twes\Domain\Shared\IdGenerator}. Accepting one would let a caller choose a primary key, which is an
 * existence oracle across tenants — a 409 tells you somebody else already has that id — and, once the Wave 10
 * client portal exists, a way to aim at a URL. `CLAUDE.md` § Gotchas 2026-08-05 is the standing ruling that a
 * UUIDv7 is an ordering artefact and never a secret, which is precisely why the surface must not let one become
 * an addressing decision a caller makes.
 *
 * **IT MAY NOT DECIDE THE TENANT.** There is no field for it and there will not be one: tenancy is ambient context
 * (`CLAUDE.md` § Gotchas 2026-07-31), and the only forgeable path to it — `TWES_TRUST_TENANT_HEADER` — exists for
 * development, is refused in production by a gate, and is deleted in Wave 7.
 *
 * **IT MAY DECIDE THE CONTACT ORDER**, and that is a real contract commitment rather than an accident of array
 * iteration. The order sent is the order stored, in a `position` column, and the order returned. Nothing sorts it.
 *
 * ## Validation
 *
 * **STRUCTURAL, AND DELIBERATELY MIRRORING THE DOMAIN'S BOUNDS RATHER THAN SUBSTITUTING FOR THEM.** Every real
 * invariant lives in {@see Client}, {@see \Twes\Domain\Client\Contact} and
 * {@see \Twes\Domain\Client\PostalAddress}; the constraints here exist so that a payload offending one is answered
 * as a 422 naming the field instead of reaching a handler that {@see CreateClientProcessor} deliberately does not
 * wrap in a `try`. Bounds are REFERENCED from the domain constants, so widening one moves both.
 *
 * `#[Assert\Valid]` is what makes the constraints on the nested DTOs run at all: without it the collection is
 * checked for its own constraints and its elements are not — a validator that reports clean on invalid input,
 * which is the vacuous-control shape `CLAUDE.md` § Gotchas records four separate times.
 */
final readonly class NewClientInput
{
    /**
     * @param list<NewContactInput> $contacts
     *
     * **THE `@param` TYPE IS LOAD-BEARING AND NOT DOCUMENTATION**, exactly as on {@see NewInvoiceInput}: PHP has no
     * generics, so `array` is all the Serializer and the OpenAPI schema factory can see from the signature, and
     * both read the element type from here. Without it the nested objects arrive as raw arrays, `#[Assert\Valid]`
     * cascades onto nothing, and the published request schema documents `contacts` as an untyped array.
     */
    public function __construct(
        /**
         * The legal or trading name. Required.
         *
         * It is the one field an invoice cannot be addressed without, which is why it is the only required field
         * on this DTO — everything else about a client can legitimately be filled in later.
         */
        #[Assert\NotBlank]
        #[Assert\Length(max: Client::MAX_NAME_LENGTH)]
        public string $name,
        /**
         * A VAT or tax registration number. Optional.
         *
         * **LENGTH ONLY.** Every jurisdiction spells one differently — a Tunisian matricule fiscal, a French numéro
         * de TVA intracommunautaire and a German USt-IdNr share no shape — and refusing a legitimate identifier is
         * worse than storing an odd one. Checking it against a registry is a Wave 9 e-invoicing concern.
         */
        #[Assert\Length(max: Client::MAX_TAX_IDENTIFIER_LENGTH)]
        public ?string $taxIdentifier = null,
        /** The whole address, or omitted entirely — see {@see PostalAddressInput}. */
        #[Assert\Valid]
        public ?PostalAddressInput $address = null,
        /**
         * The people to contact, in the order they should be shown. Optional, and bounded by the aggregate's own
         * {@see Client::MAX_CONTACTS}.
         *
         * Checking the count here as well as in `Client::withContact()` is what turns an oversized payload into
         * one message about the payload, instead of a refusal on the fifty-first `withContact()` call after fifty
         * aggregate rebuilds — the same reasoning `NewInvoiceInput` gives for bounding its line count at the edge.
         */
        #[Assert\Count(max: Client::MAX_CONTACTS)]
        #[Assert\Valid]
        public array $contacts = [],
    ) {}
}
