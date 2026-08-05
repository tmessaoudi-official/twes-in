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
 * behaves as a database index key instead of scattering inserts across a B-tree the way v4 does, while
 * still being unguessable. Unguessable matters here beyond hygiene: with sequential integers, one
 * missing authorisation check becomes enumerable access to every tenant's documents.
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
