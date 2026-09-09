<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

interface RoleRepository
{
    /** One of the three roles every company shares (owner, admin, member); null before the seed ran. */
    public function builtIn(string $name): ?Role;

    public function save(Role $role): void;
}
