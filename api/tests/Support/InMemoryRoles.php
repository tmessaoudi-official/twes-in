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

    public function save(Role $role): void
    {
        if (!\in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
    }
}
