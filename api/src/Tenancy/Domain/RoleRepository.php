<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Symfony\Component\Uid\Uuid;

interface RoleRepository
{
    /** One of the three roles every company shares (owner, admin, member); null before the seed ran. */
    public function builtIn(string $name): ?Role;

    /** A role the company may use: a built-in one or its own; null for a role of another company. */
    public function ofIdForCompany(Uuid $roleId, Uuid $companyId): ?Role;

    public function save(Role $role): void;
}
