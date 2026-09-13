<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain;

use Symfony\Component\Uid\Uuid;

interface CustomerGroupRepository
{
    /** @return list<CustomerGroup> one company's groups, by name */
    public function ofCompany(Uuid $companyId): array;

    /** Null for a group that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?CustomerGroup;

    public function ofNameInCompany(string $name, Uuid $companyId): ?CustomerGroup;

    public function save(CustomerGroup $group): void;

    public function remove(CustomerGroup $group): void;
}
