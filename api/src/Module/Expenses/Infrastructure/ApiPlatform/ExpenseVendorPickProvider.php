<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Vendors\Application\PickVendors;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/**
 * A few vendors for the expense form's picker, under the expense's own permission (see the resource beside this).
 *
 * @implements ProviderInterface<ExpenseVendorPickResource>
 */
final readonly class ExpenseVendorPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private PickVendors $vendors)
    {
    }

    /** @return list<ExpenseVendorPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::READ);

        $ids = Paging::uuids($operation, 'ids');

        return array_map(ExpenseVendorPickResource::of(...), [] === $ids
            ? $this->vendors->matching($company, Paging::text($operation) ?? '')
            : $this->vendors->byIds($company, $ids));
    }
}
