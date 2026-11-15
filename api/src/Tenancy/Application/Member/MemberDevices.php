<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Where a member is signed in, and signing them out there, for a staff phone lost or a till left signed in: the
 * company's owner's alone, whatever a custom role grants, as lifting a member's lock is, since it reads where a person
 * signs in from. An owner's own sessions are theirs to end, from « Mon compte ».
 */
final readonly class MemberDevices
{
    public function __construct(private MembershipRepository $memberships, private MemberSessions $sessions)
    {
    }

    /**
     * What refuses it, said before the proof of who is at the screen is asked: nobody proves themselves only to be told no.
     *
     * @throws OwnerOnly|OwnMembership|NotAMember
     */
    public function assertMay(Uuid $companyId, Uuid $userId, Uuid $actorUserId): void
    {
        $actor = $this->memberships->ofUserInCompany($actorUserId, $companyId);
        if (null === $actor || Role::OWNER !== $actor->getRole()->getName()) {
            throw new OwnerOnly('Only the owner sees and ends a member\'s sessions.');
        }
        if ($actorUserId->equals($userId)) {
            throw new OwnMembership('An owner\'s own sessions are ended from their account.');
        }
        if (null === $this->memberships->ofUserInCompany($userId, $companyId)) {
            throw new NotAMember(\sprintf('%s is not a member of that company.', $userId->toRfc4122()));
        }
    }

    /**
     * @return list<MemberSession>
     *
     * @throws OwnerOnly|OwnMembership|NotAMember
     */
    public function of(Uuid $companyId, Uuid $userId, Uuid $actorUserId): array
    {
        $this->assertMay($companyId, $userId, $actorUserId);

        return $this->sessions->enteredIn($userId, $companyId);
    }

    /**
     * Ends the session named, or every one the owner is shown; how many ended, 0 when the one named is not among them.
     *
     * @throws OwnerOnly|OwnMembership|NotAMember
     */
    public function end(Uuid $companyId, Uuid $userId, ?Uuid $sessionId, Uuid $actorUserId): int
    {
        $this->assertMay($companyId, $userId, $actorUserId);

        return $this->sessions->endFor($userId, $sessionId, $actorUserId, $companyId);
    }
}
