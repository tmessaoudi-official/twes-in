<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Expenses\Infrastructure\ApiPlatform\Security\ExpensePermission;
use App\Module\RecurringExpenses\Application\ManageRecurringExpenses;
use App\Module\RecurringExpenses\Infrastructure\ApiPlatform\Resource\RecurringExpenseResource;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProviderInterface<RecurringExpenseResource> */
final readonly class RecurringExpenseCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageRecurringExpenses $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<RecurringExpenseResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::READ);
        $recurring = $this->manage->list($company);
        $models = $this->manage->models($company, $recurring);

        return array_map(static fn ($one): RecurringExpenseResource => RecurringExpenseResource::of($one, $models[$one->getModelExpenseId()->toRfc4122()] ?? null), $recurring);
    }
}
