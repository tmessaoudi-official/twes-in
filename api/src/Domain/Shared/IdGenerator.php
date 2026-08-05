<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Shared;

/**
 * Fresh identifiers, injected rather than drawn from ambient randomness.
 *
 * Identifiers are UUIDv7 — see the infrastructure adapter for why: a v7 sorts by creation time, so it
 * behaves as a database index key instead of scattering inserts across a B-tree the way v4 does.
 *
 * **AN IDENTIFIER IS NOT A SECRET, and this docblock claimed the opposite until 2026-08-05** (developer ruling;
 * `CLAUDE.md` § Gotchas carries the measurements). It read *"…while still being unguessable. Unguessable matters
 * here beyond hygiene: with sequential integers, one missing authorisation check becomes enumerable access to
 * every tenant's documents"* — and it survived here, on the PORT, for a commit after the adapter it points at had
 * retracted it, which makes this the worse instance: `Domain/` outranks `Infrastructure/`, and this is the file a
 * caller programs against. What is true is only the weaker half: an id is not a COUNTER, so `/invoices/1234`
 * cannot be walked. What is false is the strong reading — the adapter's generator increments its random field
 * within a millisecond rather than redrawing it, so consecutive identifiers are correlated within 2^24, and its
 * process-global seed is recoverable from about two dozen observed values.
 *
 * So: do not build an authorisation decision on an identifier. Row-level security and, from Wave 7, the
 * permission checks are the tenancy controls. A surface where an identifier IS the credential — the
 * unauthenticated client portal, **Wave 10** — gets its own `random_bytes(32)` token, never a document key.
 *
 * Returned as a string so the domain owns no ID format library. Where an aggregate needs a typed
 * identifier, it wraps this in its own value object.
 */
interface IdGenerator
{
    /**
     * @throws \LogicException if the clock reads an instant no identifier can encode
     *
     * **DECLARED ON THE PORT, because an adapter's refusals are part of the contract a caller programs
     * against.** The v7 timestamp field is 48 unsigned bits, so a pre-epoch or year-10889 instant has no
     * representation — and the previous implementation returned a WELL-FORMED identifier with a silently WRONG
     * timestamp for both, which for a sortable key means a row that sorts into the wrong decade forever.
     * `\LogicException` and not a domain exception with a translation key: a clock outside the representable
     * range is our own configuration fault, never something a user typed, so `CLAUDE.md` § "Translation keys"
     * maps it to `error.internal`.
     */
    public function nextIdentifier(): string;
}
