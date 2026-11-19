<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

use App\Settings\Application\ReadSetting;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Who in a company grants and removes which role: the roles' order, owner above admin above member, bounds it, and a
 * company may keep the admin role to its owners (`members.admin_grants_admin`).
 */
final readonly class RoleBounds
{
    private const int OWNER_RANK = 3;
    private const int ADMIN_RANK = 2;

    public function __construct(private MembershipRepository $memberships, private CompanyRepository $companies, private ReadSetting $settings)
    {
    }

    /**
     * The built-in roles are ordered, owner above admin above member. A role the company made for itself has no place in
     * that order, so it is bounded by what it holds instead: nobody gives a role carrying a permission their own does not
     * grant, or an editor of roles could hand themselves anything by inviting a second address into one.
     *
     * @throws RoleNotManageable
     */
    public function assertMayGrant(Uuid $companyId, ?Uuid $actorUserId, Role $role): void
    {
        if (!$this->mayGrant($companyId, $actorUserId, $role)) {
            throw new RoleNotManageable(\sprintf('Your role in this company does not grant the %s role.', $role->getName()));
        }
    }

    /** Whether the actor may give this role, what the roles list tells the screen before it offers one. */
    public function mayGrant(Uuid $companyId, ?Uuid $actorUserId, Role $role): bool
    {
        $actor = $this->actorRank($companyId, $actorUserId);
        if (null === $actor || self::OWNER_RANK === $actor) {
            return true;
        }
        if (!$role->isBuiltIn()) {
            return $this->holdsEverythingOf($companyId, $actorUserId, $role);
        }
        $rank = self::rank($role->getName());

        return self::ADMIN_RANK === $actor && ($rank < self::ADMIN_RANK || (self::ADMIN_RANK === $rank && $this->adminGrantsAdmin($companyId)));
    }

    private function adminGrantsAdmin(Uuid $companyId): bool
    {
        $company = $this->companies->ofId($companyId);

        return null !== $company && AdminGrantsSettings::adminGrantsAdmin($this->settings, $company);
    }

    private function holdsEverythingOf(Uuid $companyId, ?Uuid $actorUserId, Role $role): bool
    {
        $held = null === $actorUserId ? null : $this->memberships->ofUserInCompany($actorUserId, $companyId)?->getRole();
        if (null === $held) {
            return false;
        }

        return array_all($role->getPermissions(), $held->grants(...));
    }

    /** @throws RoleNotManageable */
    public function assertMayRemove(Uuid $companyId, ?Uuid $actorUserId, Role $role): void
    {
        $actor = $this->actorRank($companyId, $actorUserId);
        if (null === $actor || self::OWNER_RANK === $actor || (self::ADMIN_RANK === $actor && self::rank($role->getName()) < self::ADMIN_RANK)) {
            return;
        }

        throw new RoleNotManageable(\sprintf('Your role in this company does not remove a member whose role is %s.', $role->getName()));
    }

    /**
     * The acting member's rank, or null when nothing bounds them here: the platform itself (no actor), or an account
     * with no membership in the company, which only an operator inviting an owner from the platform is.
     */
    private function actorRank(Uuid $companyId, ?Uuid $actorUserId): ?int
    {
        if (null === $actorUserId) {
            return null;
        }
        $membership = $this->memberships->ofUserInCompany($actorUserId, $companyId);

        return null === $membership ? null : self::rank($membership->getRole()->getName());
    }

    private static function rank(string $roleName): int
    {
        return match ($roleName) {
            Role::OWNER => self::OWNER_RANK,
            Role::ADMIN => self::ADMIN_RANK,
            Role::MEMBER, Role::CLERK, Role::ACCOUNTANT => 1,
            default => 0,
        };
    }
}
