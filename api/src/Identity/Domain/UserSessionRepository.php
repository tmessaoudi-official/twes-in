<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

interface UserSessionRepository
{
    public function ofSessionId(string $sessionId): ?UserSession;

    public function ofId(Uuid $id): ?UserSession;

    /**
     * What this account's sessions are, revoked ones included, last seen after the given moment.
     *
     * @return list<UserSession>
     */
    public function seenSince(User $user, \DateTimeImmutable $since): array;

    /** Forgets what this account's sessions were before the given moment, which no cookie can still be using. */
    public function removeOlderThan(User $user, \DateTimeImmutable $before): void;

    /** Marks every session of this account ended, as a new password or security stamp ends them all. */
    public function revokeEveryOf(User $user, \DateTimeImmutable $now): void;

    public function save(UserSession $session): void;
}
