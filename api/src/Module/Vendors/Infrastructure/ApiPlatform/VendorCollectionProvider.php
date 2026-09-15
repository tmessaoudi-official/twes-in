<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Domain\Vendor;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<VendorResource> */
final readonly class VendorCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageVendors $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<VendorResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), VendorPermission::READ);

        return array_map(static fn (Vendor $vendor) => VendorResource::of($vendor), $this->manage->list($company));
    }
}
