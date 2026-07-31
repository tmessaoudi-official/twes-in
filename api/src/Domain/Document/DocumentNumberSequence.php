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
 * The per-tenant, per-type counter behind a document number. A **port**: the domain states the contract, an
 * adapter in `Infrastructure/` holds the lock.
 *
 * **THE TENANT IS AMBIENT AND IS NOT A PARAMETER, and that is the whole reason this interface reads as though
 * tenancy did not exist.** There is no `TenantId` here and there must never be one. Which tenant's counter is
 * advanced is decided by the bound database session — `PostgresRowLevelSecurityIsolation::bind()` — exactly as
 * it is for every other tenant-owned row. Two consequences, both deliberate:
 *
 *   - `Domain/` never learns what a tenant is, so the inward-only dependency rule holds without an exception
 *     and `layer-dependencies.php` stays satisfiable.
 *   - The **database-per-tenant** mode that {@see \Twes\Infrastructure\Tenancy\TenantIsolationStrategy}
 *     documents keeps working unchanged. Under it there is no tenant column at all — the tenant *is* the
 *     connection — and a `TenantId` threaded through this signature would be a parameter with nothing to bind
 *     to. That seam is the reason the tenant must stay out of the domain, and it is why round 13's proposal to
 *     move `TenantId` into `Domain/` was reversed rather than scheduled; see {@see DocumentNumber}.
 *
 * ## What an implementation MUST guarantee
 *
 * **1. GAPLESS.** Consecutive calls return consecutive integers, with no value skipped, ever. This is a legal
 * requirement rather than a nicety: a missing number in an invoice sequence is what a tax authority reads as a
 * suppressed sale, and both France and Tunisia audit for it. It has a sharp consequence for the
 * implementation, recorded here because it is the decision that is unfixable later:
 *
 * > **A PostgreSQL `SEQUENCE` / `nextval()` IS FORBIDDEN AS THE IMPLEMENTATION.** `nextval` is deliberately
 * > non-transactional — it does not roll back — so any failed or rolled-back issue burns its number and leaves
 * > a permanent gap. That is correct behaviour for a surrogate primary key and disqualifying for a legal
 * > document number. The same objection rules out `IDENTITY` and `SERIAL` columns, and any caching
 * > (`CACHE n`) makes it worse. The shape that satisfies this contract is a per-`(tenant, type)` counter ROW
 * > taken under `SELECT ... FOR UPDATE` inside the *same* transaction that persists the document, so a
 * > rollback returns the number.
 *
 * **2. Starting at 1.** The first document of a tenant's life is number 1, not 0 — {@see DocumentNumber} and
 * {@see NumberPattern} both refuse a non-positive sequence, and this port is where the value comes from.
 *
 * **3. Independent per type.** Invoices, quotes, credits and delivery notes each count separately; that is a
 * ruling in `pricing-and-documents.plan.md` and the reason `DocumentNumber` carries its type.
 *
 * **4. Never reused.** A cancelled document keeps its number forever, so a counter never goes backwards and a
 * number is never recycled. Corrections are cancel-and-reissue, which consumes a *new* number.
 *
 * **5. Serialised.** Because the counter is gapless, concurrent issues for one `(tenant, type)` must serialise.
 * That throughput cost is accepted: two invoices sharing a number is a worse outcome than a queued request.
 *
 * ## What it may NOT be trusted for
 *
 * Uniqueness is **not** this port's promise and cannot be — an adapter cannot see numbers a previous process
 * issued. The final guarantee is a composite unique constraint on `(company_id, type, number)`, which is a
 * Wave 1 schema obligation and a `scripts/gates/schema-tenancy.php` assertion. This interface's guarantees are
 * what make that constraint never fire in practice; the constraint is what makes a broken adapter loud.
 */
interface DocumentNumberSequence
{
    /**
     * Advance this type's counter for the currently bound tenant and return the new value.
     *
     * Named `allocateNext` rather than `next` because it MUTATES: calling it consumes a number whether or not
     * the caller goes on to persist a document. A reader who takes `next()` for a peek will gap the sequence.
     * There is deliberately no peek method — see {@see DocumentNumberAllocator} for why the value must only be
     * asked for at issue time.
     *
     * @return int the new counter value, always >= 1 and always exactly one more than the previous call's
     *
     * @throws \RuntimeException if the counter cannot be advanced — an adapter must fail rather than return a
     *                           value it is unsure of, because a guessed number is a duplicate number
     */
    public function allocateNext(DocumentType $type): int;
}
