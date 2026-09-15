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
use App\Module\Vendors\Application\VendorNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<VendorResource> */
final readonly class VendorItemProvider implements ProviderInterface
{
    public function __construct(private ManageVendors $manage, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): VendorResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), VendorPermission::READ);

        try {
            return VendorResource::of($this->manage->get($company, CompanyPath::identifier($uriVariables, 'vendorId')));
        } catch (VendorNotFound $absent) {
            throw new NotFoundHttpException('No such vendor.', $absent);
        }
    }
}
