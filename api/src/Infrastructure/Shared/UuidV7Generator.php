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

use Symfony\Component\Uid\Exception\InvalidArgumentException as SymfonyInvalidArgumentException;
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
 * **Why not sequential integers — and how far that argument actually reaches.** An id here is not a counter, so
 * `/invoices/1234` cannot be walked. **64 bits of randomness follow the timestamp**, not the 74 this paragraph
 * claimed until 2026-08-05: `symfony/uid` spends 10 of the 12 `rand_a` bits on sub-millisecond precision, and
 * those 10 are a deterministic function of the clock rather than entropy. [Verified: bits 2..11 of group 3 equal
 * the sub-millisecond for 1000 of 1000 microsecond values; `symfony/uid`'s own class docblock says "a 58-bit
 * timestamp and 64 extra unique bits".] 74 was exact for the hand-written `random_bytes(10)` layout and became
 * wrong the moment that was deleted — in the same commit, in the paragraph whose whole job is to justify the
 * number.
 *
 * **AND AN IDENTIFIER IS NOT A SECRET. Do not build an authorisation decision on it** (developer ruling,
 * 2026-08-05, after a certification round measured what the previous wording promised). This paragraph used to
 * end *"with a UUID, the same bug leaks nothing without a valid identifier to begin with"* — a defence-in-depth
 * claim that a reviewer falsified in three steps. Within ONE millisecond `symfony/uid` INCREMENTS the random
 * field rather than redrawing it, by a 24-bit value: over 1999 measured pairs the delta was bounded by 2^24, so
 * an attacker holding one id faces 2^24 for its sibling, not 2^64. Worse, the increments derive from a
 * process-global seed by `hash('sha512', …)`, and **21 observed same-millisecond deltas leak 504 of its 512
 * bits** — a reviewer brute-forced the last byte and then COMPUTED a later identifier exactly, across two
 * generator instances with different clocks. Identifiers in DIFFERENT milliseconds are independent (measured), so
 * the correlation is confined to bursts — which is no comfort, because two documents in one request is the
 * ordinary case.
 *
 * So the tenancy properties this project actually relies on are PostgreSQL row-level security and, from Wave 7,
 * the permission checks — never the shape of a key. **Two constraints follow and both are recorded in
 * `CLAUDE.md` § Gotchas rather than left here:** any surface where an identifier IS the credential (the
 * unauthenticated client portal, Wave 10) gets its own `random_bytes(32)` token, and **FrankenPHP worker mode
 * must not be enabled before that exists** — a worker process is what lets the recoverable seed span requests,
 * and therefore tenants. `scripts/gates/compose-config.sh` enforces the second one, because a constraint stated
 * only in prose is the shape § Gotchas records four times over.
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

    /**
     * @throws \LogicException if the clock reads an instant the 48-bit timestamp field cannot encode
     *
     * **THE EXCEPTION IS TRANSLATED, and that is the hexagonal rule rather than tidiness.** `UuidV7::generate()`
     * raises `Symfony\Component\Uid\Exception\InvalidArgumentException` for a negative timestamp, and the
     * constructor raises the same class for a 48-bit overflow. Letting either through would put a THIRD-PARTY
     * exception class on a `Domain/` port's contract: every caller that wanted to handle it would need a `use`
     * pointing at `Infrastructure/`'s dependency, which is the outward-pointing coupling `CLAUDE.md`
     * § Architecture makes a P0 — arriving through a catch clause rather than an import, so no gate would see it.
     *
     * Both arms are an improvement on what they replaced, which is worth stating because failing where you used
     * to succeed usually is not: the hand-written layout truncated `pack('J', …)` and returned a well-formed
     * identifier carrying a WRONG instant for both inputs. A refusal is strictly better than a document that
     * sorts into the wrong decade forever.
     */
    public function nextIdentifier(): string
    {
        // READ ONCE, so the refusal below can name the instant that actually failed.
        $reading = $this->clock->now();

        try {
            // The canonical lowercase RFC 9562 form, which is what `IdGenerator` promises and what
            // `DocumentIdentity` refuses an uppercase spelling of. `generate()` returns that form and the
            // constructor re-validates it, so a malformed value cannot escape this method.
            return new UuidV7(UuidV7::generate($reading))->toRfc4122();
        } catch (SymfonyInvalidArgumentException $exception) {
            // SUB-SECOND PRECISION, and the READING THAT FAILED rather than a fresh one. `DATE_ATOM` renders at
            // second precision while the boundary is at millisecond precision, so the accepted and the refused
            // instant printed IDENTICALLY — the message named a value its own next sentence calls in range. And
            // re-reading `$this->clock->now()` here would report a different instant than the one that threw for
            // any clock that moves, which is every clock but `FrozenClock`. Round 4 filed both.
            throw new \LogicException(\sprintf(
                'The clock read %s, which a UUIDv7 cannot encode: the timestamp field is 48 UNSIGNED bits, so '
                . 'the representable range is 1970-01-01 to about 10889-08-02T05:31:50.655Z. A clock outside it '
                . 'is a configuration fault in this application, never user input.',
                $reading->format(\DateTimeInterface::RFC3339_EXTENDED),
            ), 0, $exception);
        }
    }
}
