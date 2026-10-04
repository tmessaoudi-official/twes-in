<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Expenses\Application\SummarizeExpenses;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<ExpenseSummaryResource> */
final readonly class ExpenseSummaryProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private SummarizeExpenses $summarize)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ExpenseSummaryResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::READ);

        return ExpenseSummaryResource::of($this->summarize->handle($company));
    }
}
