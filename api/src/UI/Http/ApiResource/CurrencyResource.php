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
use ApiPlatform\Metadata\GetCollection;
use Twes\UI\Http\State\CurrencyProvider;

/**
 * The supported currencies and — the part that matters — each one's DECIMAL SCALE.
 *
 * **Why this is the first resource, rather than a document or an invoice.** It is real domain data that three
 * clients genuinely need, it requires no repository (so it cannot be blocked on Wave 1's remaining persistence
 * work), and it exercises the whole HTTP path end to end: routing, the state provider, the serializer, content
 * negotiation, pagination and the OpenAPI document. A resource invented to demonstrate the stack would prove the
 * stack and nothing else; this one is code the product keeps.
 *
 * **Why the scale is not a detail.** `CLAUDE.md` is emphatic that the default currency is TND with THREE decimal
 * places, so a 2-decimal assumption is a bug for the DEFAULT currency rather than an edge case. Every client
 * formats and validates amounts, and the only alternative to serving the scale is each client hardcoding a table —
 * which is three places for the same 2-decimal bug to appear independently. Angular and Flutter can read it from
 * here instead.
 *
 * **A UI-LAYER resource, not a domain type.** It lives under `UI/Http/` and is a separate class from
 * `Twes\Domain\Money\Currency` on purpose: § Architecture forbids a framework dependency in `Domain/`, and an
 * `#[ApiResource]` attribute on the domain type would be exactly that — the same argument that keeps Doctrine's
 * mapping in `Infrastructure/`. The provider translates.
 *
 * Read-only by construction: there are no `Post`, `Put`, `Patch` or `Delete` operations, because the currency
 * registry is a property of ISO 4217 and of our own arithmetic, not something a client may change.
 */
#[ApiResource(
    shortName: 'Currency',
    description: 'A supported ISO 4217 currency and the number of decimal places its amounts carry.',
    operations: [
        new GetCollection(
            uriTemplate: '/currencies',
            // NOT paginated. The registry is a closed set of a few dozen entries that a client needs in full to
            // format anything, so paging it would make every client page through it on startup to reassemble a
            // list we could have sent once. Pagination stays ON by default for collections that GROW -- this is
            // the deliberate exception, not a precedent.
            paginationEnabled: false,
        ),
        new Get(uriTemplate: '/currencies/{code}'),
    ],
    provider: CurrencyProvider::class,
    stateless: true,
)]
final readonly class CurrencyResource
{
    public function __construct(
        /** The ISO 4217 alpha-3 code, upper-case — `TND`, `EUR`, `JPY`. Also the resource identifier. */
        public string $code,
        /**
         * Decimal places this currency's amounts are expressed in.
         *
         * 3 for TND (1 dinar = 1000 millimes), 2 for EUR, 0 for JPY. A client MUST use this rather than assuming
         * two: Tunisia's stamp duty of `0.100 TND` is 100 millimes and has to represent exactly.
         */
        public int $scale,
    ) {}
}
