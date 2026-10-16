<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Infrastructure\ApiPlatform\Payload\ExpenseSearchReader;
use App\Module\Expenses\Infrastructure\ApiPlatform\Resource\ExpenseStatusCountsResource;
use App\Module\Expenses\Infrastructure\ApiPlatform\Security\ExpensePermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<ExpenseStatusCountsResource> */
final readonly class ExpenseStatusCountsProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ManageExpenses $manage)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ExpenseStatusCountsResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::READ);

        return ExpenseStatusCountsResource::of($this->manage->statusCounts($company, ExpenseSearchReader::read(Paging::parameters($context), Paging::text($operation), [], withStatus: false)));
    }
}
