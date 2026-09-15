<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Expenses\Application\ManageExpenseCategories;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<ExpenseCategoryResource> */
final readonly class ExpenseCategoryCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageExpenseCategories $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<ExpenseCategoryResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::READ);

        return array_map(ExpenseCategoryResource::of(...), $this->manage->list($company));
    }
}
