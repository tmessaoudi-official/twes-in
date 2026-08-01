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
 * A document's identity: its TYPE and its sequence number, inseparably.
 *
 * **This class exists to make a stated hazard unrepresentable rather than merely documented.**
 * `pricing-and-documents.plan.md` rules that sequences are per type, then names the consequence:
 *
 * > *because sequences are per-type, invoice `0000041` and delivery note `0000041` can both exist. That is
 * > normal and correct on a printed document, where the title disambiguates — but any internal reference,
 * > search result or API payload must name the document **type alongside the number**, never the number
 * > alone.*
 *
 * A rule written that way depends on every future caller remembering it, and this repository has recorded
 * four times that a control enforced only by memory is not a control. So there is no way to construct a bare
 * number here: the type travels with it, {@see self::equals()} compares both, and {@see self::toString()}
 * names both. Two documents sharing digits across types are not equal, which is what stops a delivery note
 * being paid against an invoice.
 *
 * **THE IDENTITY IS `(tenant, type, sequence)` AND THIS CLASS DELIBERATELY CARRIES TWO OF THE THREE.** The
 * observation is round 13's and it is correct: the sequence is per-TENANT, so tenant A's Invoice 41 and tenant
 * B's Invoice 41 are different documents that {@see self::equals()} reports as equal and {@see self::toString()}
 * renders identically. Round 13 prescribed moving `TenantId` into `Domain/` so the type could carry all three.
 * **Round 14 REVERSED that prescription** — the observation stands, the remedy was wrong, and three things say
 * so:
 *
 *   1. **It contradicted a standing invariant nobody re-read.** `TenantId`'s own docblock already rules that it
 *      lives in `Infrastructure/` *on purpose* and calls a `company_id` reaching `Domain/` a P0 for
 *      `tenancy-security-reviewer`. Round 13's note was written against that without noticing it — precisely the
 *      "two contradictory statements and no way to tell which is current" shape CLAUDE.md § Gotchas records
 *      three times in one session.
 *   2. **It would end the database-per-tenant mode.**
 *      {@see \Twes\Infrastructure\Tenancy\TenantIsolationStrategy} documents two modes chosen by configuration,
 *      and under `database` there is no tenant column anywhere: the tenant *is* the connection. A `TenantId`
 *      inside a domain value object would have nothing to bind to, so the seam that makes the two modes
 *      interchangeable would stop being a seam.
 *   3. **The prescription does not stop here, and that is the tell.** If a tenant must sit inside a value object
 *      for its equality to be safe in a cross-tenant collection, it must equally sit inside `Invoice`, inside
 *      every `DocumentLine` and inside `Money` — any of them can land in that same collection. A field that
 *      every type needs is not a field; it is **ambient context**, which is exactly what tenancy is and exactly
 *      where `Infrastructure/` holds it.
 *
 * **So the hazard is real and is closed where it lives, which is not here.** The dangerous act is not comparing
 * two numbers; it is *materialising a collection spanning two tenants in the first place*. The tenant-less paths
 * this codebase deliberately supports — `TenantContext`'s installation and global-health-check cases, and
 * `assertStillBoundTo()`'s tenant-less branch, which exists because "the application believes it holds NO
 * tenant, so it expects to see every tenant's rows" — are the only way to reach one. The rule is therefore a
 * **boundary** rule: *no tenant-less path may hydrate a domain aggregate.* It is owed by Wave 1's repositories,
 * recorded in `build-waves.plan.md`, and it is strictly stronger than a tenant field would be, because it also
 * stops the cross-tenant total, the cross-tenant PDF and the cross-tenant export that no amount of value-object
 * equality would have caught.
 *
 * Two layers stand behind it: row-level security, which makes a *scoped* query incapable of returning both; and
 * a composite unique constraint on `(company_id, type, number)`, a Wave 1 schema obligation asserted by
 * `scripts/gates/schema-tenancy.php` and additionally attacked by `BehaviouralIsolationTest`'s GOAL 7. The gate is
 * the authoritative half and the attack is defence in depth: a probe can only re-present values its fixture carries,
 * so a partial or predicated key is invisible to it, while the catalogue reads key columns whatever the predicate.
 * (That axis was briefly deleted on 2026-08-01 and restored a day later when round 24 reproduced a cross-tenant
 * oracle without it.) Within a bound tenant — every ordinary path — {@see self::equals()} and
 * {@see self::toString()} are exact.
 */
final readonly class DocumentNumber
{
    /**
     * @param int $sequence 1-based position in this type's per-tenant sequence
     *
     * @throws \InvalidArgumentException if the sequence is not positive
     */
    public function __construct(
        private DocumentType $type,
        private NumberPattern $pattern,
        private int $sequence,
    ) {
        // ZERO IS REFUSED, and it is the value that matters: zero is what an uninitialised counter holds, so
        // accepting it means the first document of a tenant's life is silently numbered `0000000` — and nobody
        // notices until an accountant asks why the sequence starts at zero.
        if ($sequence < 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A document sequence number starts at 1, got %d. Zero is what an uninitialised counter '
                . 'holds, so accepting it would number a tenant\'s first document 0.',
                $sequence,
            ));
        }
    }

    public function type(): DocumentType
    {
        return $this->type;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    /** The rendered number alone — for the printed document, where the title disambiguates. */
    public function number(): string
    {
        return $this->pattern->format($this->sequence);
    }

    /**
     * The number WITH its type — for a log line, a search result, an API payload or an internal reference.
     *
     * This is the form the plan requires everywhere except the printed document itself. Deliberately not
     * `__toString()`: an implicit string conversion is exactly how the bare number ends up in a payload.
     */
    public function toString(): string
    {
        // `->value`, not `->name`: the backed value is the stable identifier, and a PHP case name is not.
        return $this->type->value . ' ' . $this->number();
    }

    /**
     * Equal only when the TYPE and the sequence both match — **within one tenant.**
     *
     * See the class docblock: the sequence is per-tenant, so this returns true for tenant A's Invoice 41 and
     * tenant B's Invoice 41. Valid inside a bound tenant, which is every ordinary path. It is NOT valid across
     * tenants, and by design that is not this type's problem to solve: a collection spanning two tenants is the
     * defect, and Wave 1's boundary rule — no tenant-less path may hydrate an aggregate — is what forbids one.
     *
     * Comparing digits alone is how a delivery note gets paid against an invoice. The pattern is deliberately
     * NOT compared: `0000041` and `041` are the same document rendered under two configurations, and a
     * tenant changing its pattern must not split one document's identity in two.
     */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->sequence === $other->sequence;
    }
}
