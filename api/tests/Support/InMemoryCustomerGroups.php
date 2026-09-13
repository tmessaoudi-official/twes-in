<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerGroupRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryCustomerGroups implements CustomerGroupRepository
{
    /** @var list<CustomerGroup> */
    public array $groups = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->groups, static fn (CustomerGroup $g) => $g->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (CustomerGroup $a, CustomerGroup $b) => $a->getName() <=> $b->getName());

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?CustomerGroup
    {
        foreach ($this->ofCompany($companyId) as $group) {
            if ($group->getId()->equals($id)) {
                return $group;
            }
        }

        return null;
    }

    public function ofNameInCompany(string $name, Uuid $companyId): ?CustomerGroup
    {
        foreach ($this->ofCompany($companyId) as $group) {
            if ($group->getName() === $name) {
                return $group;
            }
        }

        return null;
    }

    public function save(CustomerGroup $group): void
    {
        if (!\in_array($group, $this->groups, true)) {
            $this->groups[] = $group;
        }
    }

    public function remove(CustomerGroup $group): void
    {
        $this->groups = array_values(array_filter($this->groups, static fn (CustomerGroup $g) => $g !== $group));
    }
}
