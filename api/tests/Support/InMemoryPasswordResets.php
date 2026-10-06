<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\User;

final class InMemoryPasswordResets implements PasswordResetRepository
{
    /** @var list<PasswordReset> */
    public array $resets = [];

    public function ofTokenHash(string $tokenHash): ?PasswordReset
    {
        return array_find($this->resets, static fn (PasswordReset $reset): bool => $reset->getTokenHash() === $tokenHash);
    }

    /** What another request does while this one waits for the lock: spends the link, say. */
    public ?\Closure $whileWaitingForTheLock = null;

    public function lockedOfTokenHash(string $tokenHash): ?PasswordReset
    {
        if (null !== $this->whileWaitingForTheLock) {
            ($this->whileWaitingForTheLock)();
        }

        return $this->ofTokenHash($tokenHash);
    }

    public function openFor(User $user): array
    {
        return array_values(array_filter($this->resets, static fn (PasswordReset $reset): bool => $reset->getUser()->getId()->equals($user->getId()) && !$reset->isUsed()));
    }

    public function save(PasswordReset $reset): void
    {
        if (!\in_array($reset, $this->resets, true)) {
            $this->resets[] = $reset;
        }
    }

    public function remove(PasswordReset $reset): void
    {
        $this->resets = array_values(array_filter($this->resets, static fn (PasswordReset $each): bool => $each !== $reset));
    }
}
