<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorSearch;
use App\Module\Vendors\Infrastructure\ApiPlatform\Payload\VendorSearchReader;
use App\Module\Vendors\Infrastructure\ApiPlatform\Resource\VendorResource;
use App\Module\Vendors\Infrastructure\ApiPlatform\Security\VendorPermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

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
        $search = VendorSearchReader::read(Paging::parameters($context), Paging::text($operation), Paging::order($operation, VendorSearch::SORTS), $company);

        return $this->paging->paginator(
            $this->manage->search($company, $search, $this->paging->request($operation, $context)),
            static fn (Vendor $vendor): VendorResource => VendorResource::of($vendor),
        );
    }
}
