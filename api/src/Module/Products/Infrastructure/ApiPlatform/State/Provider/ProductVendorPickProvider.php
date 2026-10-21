<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\Vendors\ProductVendorPicks;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductVendorPickResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A few vendors for the product form's picker, to whoever may name a product's vendors: reading a product already
 * says who it is bought from, so a reader has no use for the vendor book.
 *
 * @implements ProviderInterface<ProductVendorPickResource>
 */
final readonly class ProductVendorPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ProductVendorPicks $vendors)
    {
    }

    /** @return list<ProductVendorPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);
        // Products stand without Vendors; while it is off there is no vendor to pick, as there is no vendor book.
        if (!$this->vendors->available($company)) {
            throw new NotFoundHttpException('No such company.');
        }

        $ids = Paging::uuids($operation, 'ids');

        return array_map(ProductVendorPickResource::of(...), [] === $ids
            ? $this->vendors->matching($company, Paging::text($operation) ?? '')
            : $this->vendors->ofIds($company, $ids));
    }
}
