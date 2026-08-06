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
 * What identifies and configures a persisted document, as distinct from what the document IS.
 *
 * **Why this exists rather than these three fields living on `Invoice`** (developer ruling, 2026-08-01, after the
 * alternatives were put side by side): a repository cannot be typed without them. `Invoice` carries currency, state,
 * number, lines and charges — it has no identity at all, and `DocumentRow` carries three columns the aggregate does
 * not: `id`, `type` and `vat_rounding_point`. So `save(Invoice $invoice)` had nowhere to get an id, and `find()` had
 * no way to know which document type it had loaded.
 *
 * Putting them ON the aggregate was the rejected option, and the reason is `totals()`: it takes the rounding point
 * as a PARAMETER, which is what makes inclusive-versus-exclusive tax "a parameter, never a parallel class
 * hierarchy" (`CLAUDE.md` § Architecture). Moving that onto the aggregate would make it state that must then be
 * kept consistent with the argument every caller passes, which is how the two diverge.
 *
 * **THE TENANT IS DELIBERATELY ABSENT**, and that is the same ruling as the one recorded in `CLAUDE.md` § Gotchas
 * for 2026-07-31: tenancy is AMBIENT CONTEXT, not a field. A `company_id` in `Domain/` is a P0, the
 * database-per-tenant mode `TenantIsolationStrategy` exists to allow has no such column at all, and the reductio is
 * decisive — if a tenant must sit inside a value object for safety, it must equally sit inside `Invoice`,
 * `DocumentLine` and `Money`, and a field every type needs is not a field.
 *
 * **The sentence that followed said *"The repository takes the tenant as an explicit argument instead, which is also
 * what enforces the boundary rule that no tenant-less path may hydrate an aggregate"*, and it was NOT BUILDABLE.**
 * `TenantId` lives in `Infrastructure/Tenancy/`, so a `Domain/` port declaring that parameter is an outward
 * dependency and a P0 — `scripts/gates/layer-dependencies.php` refuses it. [Verified 2026-08-06: a probe interface in
 * this namespace declaring `f(TenantId $t)` produced *"Domain references Twes\Infrastructure\Tenancy\TenantId,
 * which is outward"*, exit 1.] Corrected in place rather than annotated below, per `CLAUDE.md` § Gotchas 2026-07-29.
 *
 * What {@see InvoiceRepository} does instead: the port takes NO tenant, and the adapter is constructed with the
 * request's `TenantContext` and refuses when none is bound. That is stronger, not weaker — a parameter is satisfied
 * by whatever tenant id the caller happens to hold, including the wrong one, while a context resolved once cannot be
 * forged at the call site. Note the reductio in the paragraph above applies to a PARAMETER on every method exactly
 * as it applies to a FIELD on every type.
 *
 * **THE ID IS A STRING, and it started as a `Symfony\Component\Uid\Uuid`.** `layer-dependencies.php` refused
 * that immediately — *"Domain references the third-party namespace Symfony\Component\Uid\Uuid. The domain layer
 * has no Composer dependencies"* — and it was right to. I had written a docblock arguing the exception was fine
 * because `Uuid` is a value object with no framework behaviour, which is true and beside the point: the invariant is
 * ZERO Composer dependencies in `Domain/`, `layer-dependencies.php` enforces it with an EMPTY allowlist, and adding
 * an entry is an argument to be made in a commit message rather than assumed in a comment. So the TYPE changed, not
 * the invariant. Recorded because a gate catching its author is the whole reason it exists, and because the next
 * person to want a `Uuid` here will reach the same tempting argument.
 *
 * The canonical lowercase-hyphenated text form, validated on construction so an ill-formed id cannot reach a query.
 * `Infrastructure/` converts at the boundary — which is where `symfony/uid` belongs, and where `TenantId` already
 * keeps its own string for the same reason.
 *
 * `readonly` with public properties rather than accessors: there is no invariant to protect beyond the constructor
 * check, and a getter per field would be ceremony.
 */
final readonly class DocumentIdentity
{
    public function __construct(
        public string $id,
        public DocumentType $type,
        public VatRoundingPoint $vatRoundingPoint,
    ) {
        // A REGEX, not a library. Keeping `Domain/` dependency-free is the point of this class's string id, so the
        // check has to be one PHP can make unaided. Anchored at both ends, because an unanchored pattern accepts an
        // id with a payload appended -- and this value reaches a WHERE clause. The `/D` modifier is load-bearing:
        // without it PCRE's `$` also matches BEFORE a final newline, so "…e1f0\n" was accepted -- two unequal
        // strings for one id, which is exactly what the uppercase refusal below exists to prevent.
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id)) {
            throw new \InvalidArgumentException(\sprintf(
                'A document id must be a canonical lowercase-hyphenated UUID, got "%s". Uppercase and the '
                . 'braced or urn forms are refused rather than normalised: two spellings of one id compare '
                . 'unequal as strings, and this value is used as a key.',
                $id,
            ));
        }
    }
}
