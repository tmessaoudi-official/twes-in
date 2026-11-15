<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Tenancy;

use App\Identity\Application\Session\SessionsOfMembers;
use App\Identity\Domain\Model\UserSession;
use App\Tenancy\Application\Member\MemberSession;
use App\Tenancy\Application\Member\MemberSessions;
use Symfony\Component\Uid\Uuid;

/** The companies' context reads and ends a member's sessions through the accounts' own use case, which records and tells. */
final readonly class AccountMemberSessions implements MemberSessions
{
    public function __construct(private SessionsOfMembers $sessions)
    {
    }

    public function enteredIn(Uuid $userId, Uuid $companyId): array
    {
        return array_map(
            static fn (UserSession $session): MemberSession => new MemberSession($session->getId(), $session->getDevice(), $session->getAddress(), $session->getCreatedAt(), $session->getLastSeenAt()),
            $this->sessions->enteredIn($userId, $companyId),
        );
    }

    public function endFor(Uuid $userId, ?Uuid $sessionId, Uuid $ownerUserId, Uuid $companyId): int
    {
        return $this->sessions->end($userId, $sessionId, $ownerUserId, $companyId);
    }
}
