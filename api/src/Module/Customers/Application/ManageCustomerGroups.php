<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerGroupRepository;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Customers\Domain\InvalidCustomerGroup;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's customer groups: listed by name, each name used once, and deleted only once no customer belongs to it.
 * Audited with the names of the fields a revision changed, never their values.
 */
final readonly class ManageCustomerGroups
{
    public const string ENTITY_TYPE = 'customer_group';
    public const string CREATED = 'customer_group.created';
    public const string REVISED = 'customer_group.revised';
    public const string DELETED = 'customer_group.deleted';

    public function __construct(
        private CustomerGroupRepository $groups,
        private CustomerRepository $customers,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<CustomerGroup> */
    public function list(Company $company): array
    {
        return $this->groups->ofCompany($company->getId());
    }

    /** How many customers, active or not, belong to the group: a group holding any is kept. */
    public function customerCount(CustomerGroup $group): int
    {
        return $this->customers->countInGroup($group->getId());
    }

    /**
     * @throws CustomerGroupNameTaken
     * @throws InvalidCustomerGroup
     */
    public function create(Company $company, string $name, ?string $description, ?Uuid $actorUserId): CustomerGroup
    {
        if (null !== $this->groups->ofNameInCompany(trim($name), $company->getId())) {
            throw new CustomerGroupNameTaken();
        }
        $group = CustomerGroup::create($company, $name, $description, $this->clock->now());
        $this->groups->save($group);
        $this->record($company, $group->getId(), self::CREATED, [], $actorUserId);

        return $group;
    }

    /**
     * @throws CustomerGroupNotFound
     * @throws CustomerGroupNameTaken
     * @throws InvalidCustomerGroup
     */
    public function revise(Company $company, Uuid $id, string $name, ?string $description, ?Uuid $actorUserId): CustomerGroup
    {
        $group = $this->find($company, $id);
        $holder = $this->groups->ofNameInCompany(trim($name), $company->getId());
        if (null !== $holder && !$holder->getId()->equals($group->getId())) {
            throw new CustomerGroupNameTaken();
        }

        $before = ['name' => $group->getName(), 'description' => $group->getDescription()];
        if ($group->revise($name, $description, $this->clock->now())) {
            $after = ['name' => $group->getName(), 'description' => $group->getDescription()];
            $this->groups->save($group);
            $this->record($company, $group->getId(), self::REVISED, ['fields' => array_keys(array_diff_assoc($after, $before) + array_diff_assoc($before, $after))], $actorUserId);
        }

        return $group;
    }

    /**
     * @throws CustomerGroupNotFound
     * @throws CustomerGroupInUse
     */
    public function delete(Company $company, Uuid $id, ?Uuid $actorUserId): void
    {
        $group = $this->find($company, $id);
        if ($this->customers->countInGroup($group->getId()) > 0) {
            throw new CustomerGroupInUse();
        }
        $this->groups->remove($group);
        $this->record($company, $id, self::DELETED, [], $actorUserId);
    }

    private function find(Company $company, Uuid $id): CustomerGroup
    {
        return $this->groups->ofIdInCompany($id, $company->getId()) ?? throw new CustomerGroupNotFound();
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $groupId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $groupId, $action, $actorUserId, $changes, $company->getId()));
    }
}
