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
 * **THE IDENTITY IS ACTUALLY `(tenant, type, sequence)` AND THIS CLASS CARRIES TWO OF THE THREE.** Round 13
 * found it, and the gap is real rather than theoretical: the sequence is per-TENANT, so tenant A's Invoice 41
 * and tenant B's Invoice 41 are different documents that {@see self::equals()} reports as equal and
 * {@see self::toString()} renders identically. Row-level security stops a single *scoped* query holding both —
 * but the tenant-LESS paths this codebase deliberately supports do not: `TenantContext`'s installation and
 * global-health-check cases, and `assertStillBoundTo()`'s tenant-less branch, which exists precisely because
 * "the application believes it holds NO tenant, so it expects to see every tenant's rows". A dedup, a batch
 * import or a cross-tenant report over that set conflates two tenants' documents by this class's own equality.
 *
 * **Why it is not simply fixed here, stated rather than glossed:** `TenantId` lives in
 * `Twes\Infrastructure\Tenancy`, so referencing it from `Domain/` would be an OUTWARD dependency — a P0 by
 * `layer-dependencies.php`. The correct fix is to move `TenantId` into `Domain/`, because a tenant identifier is
 * a domain concept that every aggregate is scoped by and it sits in `Infrastructure/` only because tenancy
 * arrived as an RLS implementation detail. That is a wave-boundary change touching the tenancy seam, not a
 * findings-closure edit, so it is recorded as a **Wave 1 obligation** in `build-waves.plan.md`.
 *
 * **Until then, the constraint is a documented one and that is weaker than the type-carrying approach used for
 * TYPE — deliberately and visibly so:** {@see self::equals()} and {@see self::toString()} are valid only
 * WITHIN a bound tenant. No cross-tenant collection may key, dedup or compare on them.
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
     * tenant B's Invoice 41. Valid inside a bound tenant, which is every ordinary path; NOT valid in a
     * tenant-less or cross-tenant collection, and the type cannot express that until `TenantId` moves into
     * `Domain/` (a Wave 1 obligation).
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
