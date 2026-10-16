<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\ProductLabels;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductLabelResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<ProductLabelResource> */
final readonly class ProductLabelCollectionProvider implements ProviderInterface
{
    public function __construct(private ProductLabels $labels, private CompanyGuard $guard)
    {
    }

    /** @return list<ProductLabelResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::READ);
        // The parameter's schema already refused no id and more than a list page, with a 422.
        $labels = $this->labels->of($company, Paging::uuids($operation, 'productId'));

        return array_map(static function (array $label): ProductLabelResource {
            $row = new ProductLabelResource();
            $row->productId = $label['productId'];
            $row->reference = $label['reference'];
            $row->name = $label['name'];
            $row->codes = $label['codes'];

            return $row;
        }, $labels);
    }
}
