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
use App\Fiscal\Application\Regime\ListCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerSearch;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * One page of a company's customers, searched, narrowed and sorted in the database (docs/SPEC.md § 7, lists at scale).
 * The query parameters are declared on the operation, which checks them before this runs.
 *
 * @implements ProviderInterface<CustomerResource>
 */
final readonly class CustomerCollectionProvider implements ProviderInterface
{
    public function __construct(
        private ManageCustomers $manage,
        private ListCustomerTaxRegimes $regimes,
        private CompanyGuard $guard,
        private Paging $paging,
    ) {
    }

    /** @return TraversablePaginator<CustomerResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::READ);
        $regimes = array_map(static fn (CustomerTaxRegime $regime): string => $regime->getCode(), $this->regimes->for($company));
        $search = CustomerSearchReader::read(Paging::parameters($context), Paging::text($operation), Paging::order($operation, CustomerSearch::SORTS), $company, $regimes);

        return $this->paging->paginator(
            $this->manage->search($company, $search, $this->paging->request($operation, $context)),
            static fn (Customer $customer): CustomerResource => CustomerResource::of($customer),
        );
    }
}
