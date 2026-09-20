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

    /** The same by name, which is how a member is invited and how an invitation names the role it was sent for. */
    public function ofNameForCompany(string $name, Uuid $companyId): ?Role;

    /**
     * Every role the company may use — the built-in ones first, in the order they rank, then its own by name.
     * The order is the repository's rather than the screen's because it is the order every reader wants.
     *
     * @return list<Role>
     */
    public function forCompany(Uuid $companyId): array;

    /**
     * Whether that name is already a role this company may use, built-in or its own. A built-in name is taken for
     * every company although the row carrying it belongs to none, so the database's (company_id, name) constraint
     * would not catch it.
     */
    public function nameIsTaken(Uuid $companyId, string $name, ?Uuid $except = null): bool;

    public function save(Role $role): void;

    public function remove(Role $role): void;
}
