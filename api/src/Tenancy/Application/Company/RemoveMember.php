<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Takes someone out of a company, never the last owner: a company nobody owns can never be administered again. */
final readonly class RemoveMember
{
    public const string REMOVED = 'membership.removed';

    public function __construct(
        private MembershipRepository $memberships,
        private RoleBounds $bounds,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @throws NotAMember|LastOwner|RoleNotManageable */
    public function handle(Uuid $companyId, Uuid $userId, ?Uuid $actorUserId): void
    {
        $membership = $this->memberships->ofUserInCompany($userId, $companyId)
            ?? throw new NotAMember(\sprintf('%s is not a member of that company.', $userId->toRfc4122()));
        $this->bounds->assertMayRemove($companyId, $actorUserId, $membership->getRole());

        if (Role::OWNER === $membership->getRole()->getName() && 1 === $this->countOwners($companyId)) {
            throw new LastOwner('A company keeps at least one owner.');
        }

        $this->memberships->remove($membership);

        $this->audit->record(new AuditEntry(
            AddMember::ENTITY_TYPE,
            $membership->getId(),
            self::REMOVED,
            $actorUserId,
            ['user_id' => $userId->toRfc4122(), 'role' => $membership->getRole()->getName(), 'at' => $this->clock->now()->format(\DATE_ATOM)],
            $companyId,
        ));
    }

    private function countOwners(Uuid $companyId): int
    {
        return \count(array_filter(
            $this->memberships->ofCompany($companyId),
            static fn (Membership $m) => Role::OWNER === $m->getRole()->getName(),
        ));
    }
}
