<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\ReceiptDocuments;
use App\Module\Vendors\Application\PickVendors;
use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A few vendors for the goods receipt form's picker, under the stock write permission (see the resource beside this).
 *
 * @implements ProviderInterface<StockVendorPickResource>
 */
final readonly class StockVendorPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private PickVendors $vendors, private ModuleStates $modules)
    {
    }

    /** @return list<StockVendorPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);
        // Stock stands without Vendors; while it is off a receipt names no vendor, so there is none to pick.
        if (!$this->modules->isEnabled($company->getId(), ReceiptDocuments::VENDORS_MODULE)) {
            throw new NotFoundHttpException('No such company.');
        }

        $ids = Paging::uuids($operation, 'ids');

        return array_map(StockVendorPickResource::of(...), [] === $ids
            ? $this->vendors->matching($company, Paging::text($operation) ?? '')
            : $this->vendors->byIds($company, $ids));
    }
}
