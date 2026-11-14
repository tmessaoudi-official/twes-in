<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\AddressLockRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Whether a password, or a six-digit code, is refused here without being compared: locked from the address asking, by
 * the wrong guesses made from it, or locked everywhere, by far more made from many (`RecordFailedLogin`). Whoever guesses
 * from one address locks only that address, never the account's owner signing in from another.
 */
final readonly class PasswordLock
{
    public function __construct(
        private AddressLockRepository $addresses,
        private CallerAddress $caller,
        private ClockInterface $clock,
    ) {
    }

    /** @param bool $everywhere whether the account's own lock holds, which its caller has read already */
    public function holds(Uuid $userId, bool $everywhere): bool
    {
        return $everywhere || null !== $this->addresses->lockedUntil($userId, $this->caller->key(), $this->clock->now());
    }

    public function holdsFor(User $user): bool
    {
        return $this->holds($user->getId(), $user->isLockedAt($this->clock->now()));
    }

    /** A sign-in from here forgets the guesses made from here, unless their lock holds (`User::recordSuccessfulLogin`). */
    public function forgetHere(Uuid $userId): void
    {
        $this->addresses->forget($userId, $this->caller->key(), $this->clock->now());
    }

    /** Whether the password is locked from any address or everywhere, which someone in charge of it may lift. */
    public function anyHolds(User $user, \DateTimeImmutable $now): bool
    {
        return null !== $this->shownUntil($user, $now);
    }

    /** Every address's guesses and locks forgotten with the account's own (`User::unlock`). */
    public function forgetEverywhere(Uuid $userId): void
    {
        $this->addresses->forgetAll($userId);
    }

    /**
     * When the lock someone in charge of the account sees ends: the account's own, else the latest of its addresses',
     * since a member kept out of their own device is let back in from there too.
     */
    public function shownUntil(User $user, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $user->isLockedAt($now) ? $user->getLockedUntil() : $this->addresses->latestHolding($user->getId(), $now);
    }

    /**
     * The same for a list of accounts, in one read.
     *
     * @param list<User> $users
     *
     * @return array<string, \DateTimeImmutable> by account id (RFC 4122), the accounts with no lock holding left out
     */
    public function shownUntilOf(array $users, \DateTimeImmutable $now): array
    {
        $until = $this->addresses->latestHoldingOf(array_map(static fn (User $user): Uuid => $user->getId(), $users), $now);
        foreach ($users as $user) {
            if ($user->isLockedAt($now) && null !== $user->getLockedUntil()) {
                $until[$user->getId()->toRfc4122()] = $user->getLockedUntil();
            }
        }

        return $until;
    }
}
