<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Settings;

use App\Module\Customers\Domain\CustomerGroupRepository;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Settings\Application\PartySubject;
use App\Settings\Application\PartySubjects;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The customers module answering the settings engine about its groups and customers. Switched off, the module has
 * none to offer: their settings answer 404 like its own resources, and are kept.
 */
final readonly class CustomerPartySubjects implements PartySubjects
{
    public function __construct(private CustomerGroupRepository $groups, private CustomerRepository $customers, private ModuleStates $modules)
    {
    }

    public function hasCustomerGroup(Company $company, Uuid $groupId): bool
    {
        return $this->modules->isEnabled($company->getId(), CustomersModule::KEY) && null !== $this->groups->ofIdInCompany($groupId, $company->getId());
    }

    public function customer(Company $company, Uuid $customerId): ?PartySubject
    {
        if (!$this->modules->isEnabled($company->getId(), CustomersModule::KEY)) {
            return null;
        }
        $customer = $this->customers->ofIdInCompany($customerId, $company->getId());

        return null === $customer ? null : new PartySubject($customer->getId(), $customer->getGroup()?->getId());
    }
}
