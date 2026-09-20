<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenancy\Domain\Role;
use App\Tenancy\Domain\RoleRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryRoles implements RoleRepository
{
    /** @var list<Role> */
    public array $roles = [];

    public function builtIn(string $name): ?Role
    {
        foreach ($this->roles as $role) {
            if ($role->isBuiltIn() && $role->getName() === $name) {
                return $role;
            }
        }

        return null;
    }

    public function ofIdForCompany(Uuid $roleId, Uuid $companyId): ?Role
    {
        foreach ($this->roles as $role) {
            if ($role->getId()->equals($roleId)) {
                return $role->isBuiltIn() || true === $role->getCompany()?->getId()->equals($companyId) ? $role : null;
            }
        }

        return null;
    }

    public function forCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter(
            $this->roles,
            static fn (Role $role): bool => $role->isBuiltIn() || true === $role->getCompany()?->getId()->equals($companyId),
        ));

        usort($mine, static fn (Role $a, Role $b): int => [self::rank($a), $a->getName()] <=> [self::rank($b), $b->getName()]);

        return $mine;
    }

    public function ofNameForCompany(string $name, Uuid $companyId): ?Role
    {
        foreach ($this->forCompany($companyId) as $role) {
            if ($role->getName() === $name) {
                return $role;
            }
        }

        return null;
    }

    public function nameIsTaken(Uuid $companyId, string $name, ?Uuid $except = null): bool
    {
        foreach ($this->forCompany($companyId) as $role) {
            if ($role->getName() === $name && !(null !== $except && $role->getId()->equals($except))) {
                return true;
            }
        }

        return false;
    }

    public function save(Role $role): void
    {
        if (!\in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
    }

    public function remove(Role $role): void
    {
        $this->roles = array_values(array_filter($this->roles, static fn (Role $kept): bool => $kept !== $role));
    }

    /** The built-in three in the order they rank, then everything the company made for itself. */
    private static function rank(Role $role): int
    {
        if (!$role->isBuiltIn()) {
            return 4;
        }

        return match ($role->getName()) {
            Role::OWNER => 0,
            Role::ADMIN => 1,
            Role::MEMBER => 2,
            default => 3,
        };
    }
}
