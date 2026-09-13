<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Application\ManageCustomerGroups;
use App\Module\Customers\Domain\CustomerGroup;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<CustomerGroupResource> */
final readonly class CustomerGroupCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageCustomerGroups $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<CustomerGroupResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::READ);

        return array_map(fn (CustomerGroup $group) => CustomerGroupResource::of($group, $this->manage->customerCount($group)), $this->manage->list($company));
    }
}
