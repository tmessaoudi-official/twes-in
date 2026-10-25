<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Domain\Repository\ProductCostChangeRepository;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\VendorPurchaseResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Vendors\Domain\Repository\VendorRepository;
use App\Module\Vendors\Infrastructure\ApiPlatform\Security\VendorPermission;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<VendorPurchaseResource> */
final readonly class VendorPurchasesProvider implements ProviderInterface
{
    public function __construct(
        private ProductCostChangeRepository $history,
        private VendorRepository $vendors,
        private ModuleStates $modules,
        private CompanyGuard $guard,
        private Paging $paging,
    ) {
    }

    /** @return TraversablePaginator<VendorPurchaseResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        // A vendor's purchases are what it was paid for each thing: it takes the right to see both.
        $this->guard->companyForActing($companyId, VendorPermission::READ);
        $company = $this->guard->companyForActing($companyId, ProductPermission::COST_READ);
        $vendor = $this->modules->isEnabled($company->getId(), VendorsModule::KEY)
            ? $this->vendors->ofIdInCompany(CompanyPath::identifier($uriVariables, 'vendorId'), $company->getId())
            : null;
        if (null === $vendor) {
            throw new NotFoundHttpException('No such vendor.');
        }

        return $this->paging->paginator(
            $this->history->purchasesFromVendor($vendor->getId(), $company->getId(), $this->paging->request($operation, $context)),
            VendorPurchaseResource::of(...),
        );
    }
}
