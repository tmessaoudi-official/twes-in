<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorSearch;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * One page of a company's vendors, searched, narrowed and sorted in the database (docs/SPEC.md § 7, lists at scale).
 *
 * @implements ProviderInterface<VendorResource>
 */
final readonly class VendorCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageVendors $manage, private CompanyGuard $guard, private Paging $paging)
    {
    }

    /** @return TraversablePaginator<VendorResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), VendorPermission::READ);
        $active = Paging::value($operation, 'isActive');
        $search = new VendorSearch(Paging::text($operation), \is_bool($active) ? $active : null, Paging::order($operation, VendorSearch::SORTS));

        return $this->paging->paginator(
            $this->manage->search($company, $search, $this->paging->request($operation, $context)),
            static fn (Vendor $vendor): VendorResource => VendorResource::of($vendor),
        );
    }
}
