<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Takes someone out of a company: never oneself, which is leaving, another act than removing someone, and never the last
 * owner, as a company nobody owns can never be administered again.
 */
final readonly class RemoveMember implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'membership';
    public const string REMOVED = 'membership.removed';

    public function __construct(
        private MembershipRepository $memberships,
        private RoleBounds $bounds,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private Notifications $notifications,
    ) {
    }

    /**
     * What the actor's role refuses, said before the proof of who is at the screen is asked: nobody proves themselves
     * only to be told no.
     *
     * @throws NotAMember|OwnMembership|RoleNotManageable
     */
    public function assertMayRemove(Uuid $companyId, Uuid $userId, ?Uuid $actorUserId): void
    {
        $this->bounded($companyId, $userId, $actorUserId);
    }

    /** @throws NotAMember|OwnMembership|LastOwner|RoleNotManageable */
    public function handle(Uuid $companyId, Uuid $userId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($companyId, $userId, $actorUserId): void {
            $membership = $this->bounded($companyId, $userId, $actorUserId);

            if (Role::OWNER === $membership->getRole()->getName() && 1 === $this->countOwners($companyId)) {
                throw new LastOwner('A company keeps at least one owner.');
            }

            $this->memberships->remove($membership);

            $this->audit->record(new AuditEntry(
                self::ENTITY_TYPE,
                $membership->getId(),
                self::REMOVED,
                $actorUserId,
                ['user_id' => $userId->toRfc4122(), 'role' => $membership->getRole()->getName(), 'at' => $this->clock->now()->format(\DATE_ATOM)],
                $companyId,
            ));

            // About the account rather than the company: a company's notice is shown to its members only, which they
            // no longer are, and they would otherwise find the company gone from their list with no word why.
            $this->notifications->publish(new Notification('user:'.$userId->toRfc4122(), self::REMOVED, ['company' => $membership->getCompany()->getName()]));
        });
    }

    /**
     * The membership to remove, once the actor is someone else whose role may take that one away.
     *
     * @throws NotAMember|OwnMembership|RoleNotManageable
     */
    private function bounded(Uuid $companyId, Uuid $userId, ?Uuid $actorUserId): Membership
    {
        if (null !== $actorUserId && $actorUserId->equals($userId)) {
            throw new OwnMembership('Nobody removes themselves from a company.');
        }
        $membership = $this->memberships->ofUserInCompany($userId, $companyId)
            ?? throw new NotAMember(\sprintf('%s is not a member of that company.', $userId->toRfc4122()));
        $this->bounds->assertMayRemove($companyId, $actorUserId, $membership->getRole());

        return $membership;
    }

    private function countOwners(Uuid $companyId): int
    {
        return \count(array_filter(
            $this->memberships->ofCompany($companyId),
            static fn (Membership $m) => Role::OWNER === $m->getRole()->getName(),
        ));
    }

    public function auditActions(): array
    {
        return [
            self::REMOVED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
