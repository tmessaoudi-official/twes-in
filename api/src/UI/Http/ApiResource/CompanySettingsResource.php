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
use ApiPlatform\Metadata\Put;
use Twes\UI\Http\State\CompanySettingsProcessor;
use Twes\UI\Http\State\CompanySettingsProvider;

/**
 * The calling company's document settings, as the API returns them.
 *
 * ## A SINGLETON RESOURCE: no identifier, no collection, no POST, no DELETE
 *
 * `/api/settings` and not `/api/companies/{id}/settings`, because the tenant is **ambient** rather than a path
 * segment. `CLAUDE.md` § Gotchas 2026-07-31 rules that tenancy is context and not data, and a tenant id in the URL
 * would be a second, forgeable answer to a question the request already answers — the shape
 * `TWES_TRUST_TENANT_HEADER` exists to keep out of production. There is nothing to identify: a caller has exactly
 * one settings resource and it is the one belonging to whoever they are.
 *
 * **No `POST` and no `DELETE`, and that is a domain statement rather than a reduced surface.** Every tenant has
 * settings from the moment it exists — expressed as {@see \Twes\Domain\Settings\CompanySettings::defaults()} until
 * it chooses otherwise — so there is no creation event and no state in which a company has none. `GET` on a tenant
 * with no row returns the defaults rather than a 404, which is why {@see CompanySettingsProvider} can never answer
 * null.
 *
 * **`PUT` and not `PATCH`.** The resource is two scalars that are always both present, so a full replacement is
 * the honest verb and matches an adapter whose write is a single upsert. A PATCH would need a rule for an absent
 * field, and "absent means keep" is precisely the ambiguity that makes a later change to a TAX setting hard to
 * audit — which of the two fields a given request meant to change would stop being visible in the request itself.
 *
 * ## What is deliberately NOT here
 *
 * Company name, address, logo and e-mail identity are branding and belong to licensing invariant 9's configuration
 * surface, not to this resource, which exists for settings that change how a DOCUMENT is computed or rendered. The
 * two present are exactly the two hardwires the settings table retired; a third is added when something needs it,
 * not in anticipation.
 */
#[ApiResource(
    shortName: 'CompanySettings',
    operations: [
        new Get(
            uriTemplate: '/settings',
            // NO `{id}`, so API Platform must be told this operation identifies nothing; without it the router
            // expects an identifier the resource does not have.
            read: true,
            provider: CompanySettingsProvider::class,
        ),
        new Put(
            uriTemplate: '/settings',
            // A SEPARATE INPUT CLASS, as `NewInvoiceInput` is for invoices and for the same reason: an output DTO
            // reused as input has to make its fields optional and leaves a reader unable to tell the two apart.
            input: CompanySettingsInput::class,
            // WITHOUT THIS, a JSON number where a string is declared -- or any other type mismatch -- produces an
            // opaque `400 {"detail":"The input data is misformatted."}` naming no field. Same reasoning as the
            // invoice write path, where it was needed to name `lines[0].unitNet`.
            denormalizationContext: ['collect_denormalization_errors' => true],
            processor: CompanySettingsProcessor::class,
        ),
    ],
)]
final readonly class CompanySettingsResource
{
    public function __construct(
        /** How wide a NEW document number is rendered — `0000041` at 7. Governs new numbers only. */
        public int $numberPatternWidth,
        /**
         * Where VAT is rounded on a NEW document: `per_line` or `per_rate_group`.
         *
         * The backed value rather than the enum, matching `InvoiceTotalsResource`: the wire is a contract and an
         * enum's identity is ours. `CLAUDE.md` § "Translation keys" also forbids interpolating a backed value into
         * a user-facing sentence, which is a UI concern this DTO deliberately does not solve.
         */
        public string $defaultVatRoundingPoint,
    ) {}
}
