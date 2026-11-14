<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain\Repository;

use Symfony\Component\Uid\Uuid;

/** The locks wrong guesses set on a password from one address (`PasswordAddressLock`). */
interface AddressLockRepository
{
    /** When the lock on this account's password from this address ends, if it holds now. */
    public function lockedUntil(Uuid $userId, string $address, \DateTimeImmutable $now): ?\DateTimeImmutable;

    /**
     * One wrong guess from this address, counted unless its lock holds, which a guess never extends; the lock is set,
     * until $lockedUntil, by the guess that brings the count to $lockAfter or past it.
     *
     * @return int|null the address's count with this guess, null when its lock held and nothing was counted
     */
    public function recordFailure(Uuid $userId, string $address, \DateTimeImmutable $now, int $lockAfter, \DateTimeImmutable $lockedUntil): ?int;

    /** A sign-in from this address forgets its guesses, unless its lock holds, which a passkey or recovery code leaves. */
    public function forget(Uuid $userId, string $address, \DateTimeImmutable $now): void;

    /** Every address's guesses and locks forgotten, as a password reset or a lifted lock forgets them. */
    public function forgetAll(Uuid $userId): void;

    /** When the latest of this account's address locks still holding ends, if any holds. */
    public function latestHolding(Uuid $userId, \DateTimeImmutable $now): ?\DateTimeImmutable;

    /**
     * The same for many accounts in one read, for a list.
     *
     * @param list<Uuid> $userIds
     *
     * @return array<string, \DateTimeImmutable> by account id (RFC 4122), the accounts with no lock holding left out
     */
    public function latestHoldingOf(array $userIds, \DateTimeImmutable $now): array;
}
