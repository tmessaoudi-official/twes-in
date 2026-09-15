<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Domain\Expense;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<ExpenseResource> */
final readonly class ExpenseCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageExpenses $manage, private CurrencyScales $scales, private CompanyGuard $guard)
    {
    }

    /** @return list<ExpenseResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::READ);
        $scale = $this->scales->of($company->getCurrency());

        return array_map(fn (Expense $expense) => ExpenseResource::of($expense, $scale, $this->manage->attachmentCount($expense)), $this->manage->list($company));
    }
}
