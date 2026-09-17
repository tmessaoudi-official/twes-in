<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerSearch;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\Uid\Uuid;

/**
 * One page of a company's customers, searched, narrowed and sorted in the database (docs/SPEC.md § 7, lists at scale).
 * The query parameters are declared on the operation, which checks them before this runs.
 *
 * @implements ProviderInterface<CustomerResource>
 */
final readonly class CustomerCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageCustomers $manage, private CompanyGuard $guard, private Paging $paging)
    {
    }

    /** @return TraversablePaginator<CustomerResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::READ);
        $kind = Paging::value($operation, 'kind');
        $group = Paging::value($operation, 'customerGroupId');
        $active = Paging::value($operation, 'isActive');
        $search = new CustomerSearch(
            Paging::text($operation),
            \is_string($kind) ? CustomerKind::from($kind) : null,
            \is_string($group) ? Uuid::fromString($group) : null,
            \is_bool($active) ? $active : null,
            Paging::order($operation, CustomerSearch::SORTS),
        );

        return $this->paging->paginator(
            $this->manage->search($company, $search, $this->paging->request($operation, $context)),
            static fn (Customer $customer): CustomerResource => CustomerResource::of($customer),
        );
    }
}
