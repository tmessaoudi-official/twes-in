<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Expenses\Infrastructure\ApiPlatform\Security\ExpensePermission;
use App\Module\RecurringExpenses\Application\ManageRecurringExpenses;
use App\Module\RecurringExpenses\Application\RecurringExpenseNotFound;
use App\Module\RecurringExpenses\Infrastructure\ApiPlatform\Resource\RecurringExpenseResource;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<RecurringExpenseResource, null> */
final readonly class DeleteRecurringExpenseProcessor implements ProcessorInterface
{
    public function __construct(private ManageRecurringExpenses $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::WRITE);

        try {
            $this->manage->delete($company, CompanyPath::identifier($uriVariables, 'recurringExpenseId'), $this->guard->account()->getId());
        } catch (RecurringExpenseNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return null;
    }
}
