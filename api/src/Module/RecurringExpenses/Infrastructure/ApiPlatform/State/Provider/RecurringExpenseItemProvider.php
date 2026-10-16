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
use App\Module\RecurringExpenses\Application\RecurringExpenseNotFound;
use App\Module\RecurringExpenses\Infrastructure\ApiPlatform\Resource\RecurringExpenseResource;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<RecurringExpenseResource> */
final readonly class RecurringExpenseItemProvider implements ProviderInterface
{
    public function __construct(private ManageRecurringExpenses $manage, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RecurringExpenseResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::READ);
        try {
            $recurring = $this->manage->get($company, CompanyPath::identifier($uriVariables, 'recurringExpenseId'));
        } catch (RecurringExpenseNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return RecurringExpenseResource::of($recurring, $this->manage->models($company, [$recurring])[$recurring->getModelExpenseId()->toRfc4122()] ?? null);
    }
}
