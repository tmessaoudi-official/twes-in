<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/**
 * A member who locked their password with wrong guesses is let back in by the company's owner, rather than waiting or
 * asking for the mailed link (row 241). The owner's alone, whatever a custom role grants, as closing the company is:
 * lifting a lock gives guesses back to whoever made them, so it stays with the person answerable for the company. An
 * owner's own lock is the platform's to lift.
 */
final readonly class LiftMemberLock
{
    public function __construct(
        private MembershipRepository $memberships,
        private PasswordLocks $locks,
        private Transactions $transactions,
    ) {
    }

    /**
     * What refuses it, said before the proof of who is at the screen is asked: nobody proves themselves only to be told no.
     *
     * @throws OwnerOnly|OwnMembership|NotAMember
     */
    public function assertMayLift(Uuid $companyId, Uuid $userId, Uuid $actorUserId): void
    {
        $actor = $this->memberships->ofUserInCompany($actorUserId, $companyId);
        if (null === $actor || Role::OWNER !== $actor->getRole()->getName()) {
            throw new OwnerOnly('Only the owner lifts the lock on a member\'s password.');
        }
        if ($actorUserId->equals($userId)) {
            throw new OwnMembership('An owner\'s own lock is lifted by the platform, or by the mailed link.');
        }
        if (null === $this->memberships->ofUserInCompany($userId, $companyId)) {
            throw new NotAMember(\sprintf('%s is not a member of that company.', $userId->toRfc4122()));
        }
    }

    /** @throws OwnerOnly|OwnMembership|NotAMember */
    public function handle(Uuid $companyId, Uuid $userId, Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($companyId, $userId, $actorUserId): void {
            $this->assertMayLift($companyId, $userId, $actorUserId);
            $this->locks->liftForMember($userId, $actorUserId, $companyId);
        });
    }
}
