<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Domain\User;
use App\Identity\Domain\UserSession;
use App\Identity\Domain\UserSessionRepository;
use Symfony\Component\Uid\Uuid;

final class InMemorySessions implements UserSessionRepository
{
    /** @var list<UserSession> */
    public array $all = [];

    public function ofSessionId(string $sessionId): ?UserSession
    {
        return array_find($this->all, static fn (UserSession $session): bool => $session->isFor($sessionId));
    }

    public function ofId(Uuid $id): ?UserSession
    {
        return array_find($this->all, static fn (UserSession $session): bool => $session->getId()->equals($id));
    }

    public function seenSince(User $user, \DateTimeImmutable $since): array
    {
        return array_values(array_filter($this->all, static fn (UserSession $session): bool => $session->getUser()->getId()->equals($user->getId()) && $session->getLastSeenAt() > $since));
    }

    public function removeOlderThan(User $user, \DateTimeImmutable $before): void
    {
        $this->all = array_values(array_filter($this->all, static fn (UserSession $session): bool => !$session->getUser()->getId()->equals($user->getId()) || $session->getLastSeenAt() >= $before));
    }

    public function save(UserSession $session): void
    {
        if (!\in_array($session, $this->all, true)) {
            $this->all[] = $session;
        }
    }
}
