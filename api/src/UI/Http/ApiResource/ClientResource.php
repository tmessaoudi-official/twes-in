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

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use Twes\UI\Http\State\ClientProvider;
use Twes\UI\Http\State\CreateClientProcessor;

/**
 * One of the calling company's clients, as the API returns it.
 *
 * ## The two operations, and the ones deliberately absent
 *
 * `POST /api/clients` creates one; `GET /api/clients/{id}` fetches one. **There is no collection `GET`, no `PUT`
 * and no `DELETE`, and each absence is a decision rather than an unfinished surface:**
 *
 * - **No collection `GET` yet** because a list needs pagination, filtering and an ordering decided once, and
 *   `CLAUDE.md` § "The API contract is ours to design" rejects upstream's `per_page=999999` outright. It is API
 *   Platform's pagination extension when it lands, never a hand-rolled limit/offset on this endpoint.
 * - **No `PUT` yet**, even though a client record is MEANT to be edited — an address changes, a company is renamed,
 *   and `DoctrineClientRepository::save()` is an unguarded upsert precisely so it can be. What `PUT` needs first is
 *   the contact question answered: a full replacement would have to accept contact IDS on the way in, which is the
 *   one thing this resource refuses on the way in today, and inventing that rule as a side effect of adding an
 *   edit endpoint is how a contract acquires a shape nobody argued for.
 * - **No `DELETE`** because a client referenced by an issued invoice cannot be removed without deciding what
 *   happens to a legal document that names it. That is Wave 2's question, not this one's.
 *
 * **THE TENANT IS NOT IN THE PATH.** `/api/clients/{id}` and never `/api/companies/{tenant}/clients/{id}`: tenancy
 * is ambient context (`CLAUDE.md` § Gotchas 2026-07-31), and a tenant id in the URL would be a second, forgeable
 * answer to a question the request already answers. A client belonging to another tenant is indistinguishable from
 * one that does not exist — see {@see ClientProvider}.
 */
#[ApiResource(
    shortName: 'Client',
    operations: [
        new Get(
            uriTemplate: '/clients/{id}',
            provider: ClientProvider::class,
        ),
        new Post(
            uriTemplate: '/clients',
            // A SEPARATE INPUT CLASS, as `NewInvoiceInput` and `CompanySettingsInput` are, and for the same reason:
            // an output DTO reused as input has to make its fields optional and leaves a reader unable to tell
            // which fields a caller may send from which fields it will receive.
            input: NewClientInput::class,
            // WITHOUT THIS a type mismatch anywhere in the body -- a JSON number where a string is declared, an
            // object where the contact list is expected -- produces an opaque `400 {"detail":"The input data is
            // misformatted."}` naming no field at all. The invoice write path needed it to name `lines[0].unitNet`;
            // here it is what names `contacts[2].email`.
            denormalizationContext: ['collect_denormalization_errors' => true],
            processor: CreateClientProcessor::class,
        ),
    ],
)]
final readonly class ClientResource
{
    /**
     * @param list<ContactResource> $contacts
     *
     * **THE `@param` TYPE IS LOAD-BEARING**, for the reason {@see NewInvoiceInput} sets out at length: PHP has no
     * generics, so `array` is all the Serializer and the OpenAPI schema factory can read from the signature, and
     * both take the element type from here. Without it the published response schema documents `contacts` as an
     * untyped array — a contract document that is wrong about the contract.
     */
    public function __construct(
        /** Server-generated. Stable for the life of the client, and the id `GET /api/clients/{id}` takes. */
        public string $id,
        /** The legal or trading name an invoice is addressed to. Never empty. */
        public string $name,
        /**
         * A VAT or tax registration number, or absent.
         *
         * Returned exactly as it was stored. **The FORMAT is deliberately not validated** — every jurisdiction
         * spells one differently and a caller whose legitimate identifier is refused has no recourse. Only its
         * length is bounded.
         */
        public ?string $taxIdentifier,
        /** The whole address, or `null`. Never a partially populated object — see {@see PostalAddressResource}. */
        public ?PostalAddressResource $address,
        /** In the order the client arranged them, which is part of the contract — see {@see ContactResource}. */
        public array $contacts,
    ) {}
}
