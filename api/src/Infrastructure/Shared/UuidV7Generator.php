<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Shared;

use Symfony\Component\Uid\UuidV7;
use Twes\Domain\Shared\Clock;
use Twes\Domain\Shared\IdGenerator;

/**
 * UUIDv7 identifiers, per RFC 9562 section 5.7.
 *
 * **Why v7 and not v4.** A v7's leading 48 bits are a millisecond timestamp, so new identifiers sort
 * after old ones. As a primary key that means inserts append to the right-hand edge of the index
 * instead of landing at random depths, which is the difference between a B-tree that stays compact and
 * one that fragments as the table grows. Invoices, payments and delivery notes are all
 * append-heavy tables where that compounds.
 *
 * **Why not sequential integers.** 74 bits of randomness follow the timestamp, so an identifier is not
 * guessable. That is a tenancy property, not a cosmetic one: with `/invoices/1234`, a single missing
 * authorisation check becomes enumerable access to every tenant's documents. With a UUID, the same bug
 * leaks nothing without a valid identifier to begin with.
 *
 * **`symfony/uid`, ADOPTED 2026-08-05 — and the paragraph that argued against it was false.** It read:
 * *"Why hand-written rather than symfony/uid. It would be one MIT line in composer.json and is the intended
 * dependency; it cannot be installed in the environment this landed in, since every Composer dist URL is
 * refused by egress policy."* `symfony/uid` was already installed, already in `composer.json` as a runtime
 * requirement, and already used by five files in this same layer — every Doctrine row entity types its
 * identifier columns as `Symfony\Component\Uid\Uuid`. So the stated blocker was not merely stale, it was
 * contradicted by the imports two directories away. `CLAUDE.md` § Gotchas: *"a paragraph explaining why
 * something cannot work is the highest-value thing in this repository to spend ten minutes disproving."*
 *
 * **THE CLOCK PORT SURVIVES, which is the only thing that made adopting a real decision rather than a
 * formality.** `UuidV7::generate()` takes an optional `\DateTimeInterface`, so the injected {@see Clock} is
 * still what determines the timestamp and the tests stay deterministic. Had it read `microtime()`
 * unconditionally, keeping the hand-written version would have been correct — ambient time is permitted in
 * `Infrastructure/`, but a generator whose output cannot be frozen is a generator whose ordering property
 * cannot be tested.
 *
 * **And it fixes a real defect the hand-written version had: WITHIN-MILLISECOND ORDERING.** Ours called
 * `random_bytes(10)` afresh on every call, so two identifiers minted in the same millisecond sorted
 * ARBITRARILY — directly against the sortability this class's first paragraph gives as the whole reason for
 * choosing v7 over v4. Symfony re-randomises only when the millisecond changes and otherwise INCREMENTS the
 * random field, so same-millisecond identifiers are monotonic. Two documents created in one request is the
 * ordinary case, not the exotic one, so this was reachable rather than theoretical.
 */
final readonly class UuidV7Generator implements IdGenerator
{
    public function __construct(private Clock $clock) {}

    public function nextIdentifier(): string
    {
        // The canonical lowercase RFC 9562 form, which is what `IdGenerator` promises and what
        // `DocumentIdentity` refuses an uppercase spelling of. `generate()` returns that form and the
        // constructor re-validates it, so a malformed value cannot escape this method.
        return new UuidV7(UuidV7::generate($this->clock->now()))->toRfc4122();
    }
}
