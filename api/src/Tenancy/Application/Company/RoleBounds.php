<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;
use Symfony\Component\Uid\Uuid;

/**
 * Who in a company grants and removes which role (docs/SPEC.md § 7, 2026-09-15): the roles' order, owner above admin
 * above member, bounds it.
 */
final readonly class RoleBounds
{
    private const int OWNER_RANK = 3;
    private const int ADMIN_RANK = 2;

    public function __construct(private MembershipRepository $memberships)
    {
    }

    /** @throws RoleNotManageable */
    public function assertMayGrant(Uuid $companyId, ?Uuid $actorUserId, string $roleName): void
    {
        $actor = $this->actorRank($companyId, $actorUserId);
        if (null === $actor || self::OWNER_RANK === $actor || (self::ADMIN_RANK === $actor && self::rank($roleName) <= self::ADMIN_RANK)) {
            return;
        }

        throw new RoleNotManageable(\sprintf('Your role in this company does not grant the %s role.', $roleName));
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
     * with no membership in the company, which only an operator's reaches (docs/SPEC.md § 8 row 19 narrows those).
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
            Role::MEMBER => 1,
            default => 0,
        };
    }
}
