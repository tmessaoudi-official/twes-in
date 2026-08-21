<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Settings;

/**
 * Reading and writing one company's settings. A PORT — the adapter is in `Infrastructure/`.
 *
 * Beside the type it serves, per `CLAUDE.md` § Architecture. It carries **no tenant parameter**, for the
 * reason {@see \Twes\Domain\Document\InvoiceRepository} states at length and which is settled rather than
 * re-argued here: `TenantId` lives in `Infrastructure\Tenancy`, so naming it in a `Domain/` interface is an
 * outward dependency and a P0 that `scripts/gates/layer-dependencies.php` refuses; and passing the id as a
 * bare `string` would satisfy the gate while losing the point, because a `string` parameter is not a tenancy
 * control, it is a hole with a type. Tenancy is ambient context (§ Gotchas 2026-07-31), so the adapter is
 * constructed with the request's `TenantContext` and cannot be handed a different tenant at the call site.
 */
interface CompanySettingsRepository
{
    /**
     * The current tenant's settings, or the documented defaults when it has never chosen any.
     *
     * **AN UNBOUND SESSION AND A MISSING ROW MUST NEVER LOOK ALIKE, AND THIS IS THE WHOLE REASON THIS
     * DOCBLOCK IS LONG.** Under row-level security an unbound connection sees NOTHING rather than everything
     * — that fail-closed direction is the entire tenancy design (`CLAUDE.md` § Gotchas 2026-07-29), and
     * § Gotchas 2026-08-07 records what it cost when the binding was missing: `twes.tenant_id` was never
     * written, so every read returned zero rows and every write was refused. An implementation that answered
     * "no row, therefore defaults" would convert exactly that fail-closed refusal into a **silent wrong
     * answer**: a tenant that had configured width 9 and `PerLine` would be served width 7 and
     * `PerRateGroup`, with no error anywhere and every default-shaped fixture still green.
     *
     * So an implementation MUST prove a tenant is bound BEFORE it interprets an empty result, and refuse
     * otherwise. "Bound" is not "the query returned nothing" — those are the two states this method exists to
     * keep apart.
     *
     * **WHY DEFAULTS AT ALL, RATHER THAN REFUSING A MISSING ROW.** Nothing creates a settings row today: a
     * tenant exists the moment a request carries its id, and there is no tenant-provisioning path until
     * Wave 7 brings authentication. Refusing would mean every tenant is unusable until someone writes a row
     * by hand, which is a worse failure than a documented default — and the defaults reproduce the two
     * retired hardwires exactly, so an unconfigured tenant behaves today precisely as it did before this
     * table existed. When tenant provisioning arrives, seeding a row at that point and tightening this method
     * to refuse is a strictly smaller change than the reverse.
     *
     * **AND THIS IS WHY A PURE READ REQUIRES AN ACTIVE TRANSACTION.** "Prove a tenant is bound" cannot mean
     * "ask the PHP-side context", because that is precisely what was true and insufficient on 2026-08-07: the
     * in-memory context held a tenant while nothing had written `twes.tenant_id` on the connection, so every
     * read returned nothing. The binding is TRANSACTION-LOCAL by construction (`set_config(…, true)`, because
     * a session-scoped `SET` leaks to whoever gets the pooled connection next), and it is written when a
     * transaction begins. So requiring one makes the binding STRUCTURALLY PRESENT rather than something each
     * implementation must remember to verify — the same move `findForMutation()` makes in stating a guarantee
     * instead of naming a lock. An implementation is free to satisfy it another way if its storage has no
     * transactions; what it may not do is answer "defaults" from a read it cannot prove was scoped.
     *
     * @throws \RuntimeException if there is no current tenant, or no active transaction — see above; these are
     *                           the two cases that must not be laundered into {@see CompanySettings::defaults()}
     */
    public function forCurrentTenant(): CompanySettings;

    /**
     * Store the current tenant's settings, creating the row when it does not exist yet.
     *
     * IDEMPOTENT: saving twice stores the second state, it does not create a second row. That is a property of
     * the data rather than of the adapter — there is exactly one settings row per tenant, and the primary key
     * is what makes it so.
     *
     * **NO TRANSACTION OF ITS OWN**, matching {@see \Twes\Domain\Document\InvoiceRepository}: the adapter
     * refuses to write outside an active transaction rather than opening one. Here the reason is narrower than
     * the invoice repository's gapless-number argument but the same in kind — the tenant binding this write
     * depends on is TRANSACTION-LOCAL (`set_config(..., true)`), so a write outside a transaction is a write
     * with no binding, which row-level security refuses anyway. Refusing early gives a caller a message that
     * names the cause instead of a `42501` that does not.
     *
     * @throws \RuntimeException if there is no active transaction, or no current tenant
     */
    public function save(CompanySettings $settings): void;
}
