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
 * **Why hand-written rather than symfony/uid.** It would be one MIT line in composer.json and is the
 * intended dependency; it cannot be installed in the environment this landed in, since every Composer
 * dist URL is refused by egress policy. The layout below is 20 lines and fully pinned by tests, so this
 * is a small, verified implementation rather than a blocked wave. Swapping in symfony/uid later changes
 * this one class and no caller.
 */
final readonly class UuidV7Generator implements IdGenerator
{
    public function __construct(private Clock $clock) {}

    public function nextIdentifier(): string
    {
        // 'Uv' is seconds-then-milliseconds, i.e. the Unix timestamp in milliseconds.
        $milliseconds = (int) $this->clock->now()->format('Uv');

        // pack('J') gives 8 big-endian bytes; the low 6 carry the 48-bit timestamp field.
        $timestamp = substr(pack('J', $milliseconds), 2, 6);
        $bytes = $timestamp . random_bytes(10);

        // Byte 6's high nibble is the version: 0111 = 7.
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);

        // Byte 8's two high bits are the variant: 10 = RFC 9562.
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
