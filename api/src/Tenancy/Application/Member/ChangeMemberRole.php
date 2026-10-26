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
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\MembershipRepository;
use App\Tenancy\Domain\Repository\RoleRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Gives a member another role where they are listed, rather than removing them and inviting them again: whoever may
 * neither remove someone at their present role nor give the new one does not change it, and a company keeps an owner,
 * so the last one is never lowered, while an owner steps down beside another. The person's next request reads the new
 * rights; the journal says from what to what.
 */
final readonly class ChangeMemberRole implements DeclaresAuditActions
{
    public const string ROLE_CHANGED = 'membership.role_changed';

    public function __construct(
        private MembershipRepository $memberships,
        private RoleRepository $roles,
        private RoleBounds $bounds,
        private AuditTrail $audit,
        private Transactions $transactions,
    ) {
    }

    /**
     * What the actor's role refuses, said before anything else is asked of them: the processor runs it before the
     * proof of who is at the screen, so nobody proves themselves only to be told no.
     *
     * @throws NotAMember|UnknownRole|RoleNotManageable
     */
    public function assertMayChange(Uuid $companyId, Uuid $userId, string $roleName, ?Uuid $actorUserId): void
    {
        $this->bounded($companyId, $userId, $roleName, $actorUserId);
    }

    /** @throws NotAMember|UnknownRole|RoleNotManageable|LastOwner */
    public function handle(Uuid $companyId, Uuid $userId, string $roleName, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($companyId, $userId, $roleName, $actorUserId): void {
            $bounded = $this->bounded($companyId, $userId, $roleName, $actorUserId);
            if (null === $bounded) {
                return;
            }
            [$membership, $from, $role] = $bounded;
            if (Role::OWNER === $from->getName() && 1 === $this->countOwners($companyId)) {
                throw new LastOwner('A company keeps at least one owner.');
            }

            $membership->changeRole($role);
            $this->memberships->save($membership);

            $this->audit->record(new AuditEntry(
                RemoveMember::ENTITY_TYPE,
                $membership->getId(),
                self::ROLE_CHANGED,
                $actorUserId,
                ['user_id' => $userId->toRfc4122(), 'from' => $from->getName(), 'to' => $role->getName()],
                $companyId,
            ));
        });
    }

    /**
     * The membership, its role and the one asked for, once the bounds allow taking the one and giving the other; null
     * when the role asked for is the one held, which changes nothing and so asks nothing.
     *
     * @return array{Membership, Role, Role}|null
     *
     * @throws NotAMember|UnknownRole|RoleNotManageable
     */
    private function bounded(Uuid $companyId, Uuid $userId, string $roleName, ?Uuid $actorUserId): ?array
    {
        $membership = $this->memberships->ofUserInCompany($userId, $companyId)
            ?? throw new NotAMember(\sprintf('%s is not a member of that company.', $userId->toRfc4122()));
        $role = $this->roles->ofNameForCompany($roleName, $companyId)
            ?? throw new UnknownRole(\sprintf('"%s" is not a role of that company.', $roleName));
        $from = $membership->getRole();
        if ($from->getId()->equals($role->getId())) {
            return null;
        }
        $this->bounds->assertMayRemove($companyId, $actorUserId, $from);
        $this->bounds->assertMayGrant($companyId, $actorUserId, $role);

        return [$membership, $from, $role];
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
            self::ROLE_CHANGED => new Recorded(RemoveMember::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
