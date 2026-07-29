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
    public function nextIdentifier(): string;
}
