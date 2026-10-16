<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Module\Expenses\Application\ExpenseNotFound;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Infrastructure\ApiPlatform\Resource\ExpenseResource;
use App\Module\Expenses\Infrastructure\ApiPlatform\Security\ExpensePermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<ExpenseResource> */
final readonly class ExpenseItemProvider implements ProviderInterface
{
    public function __construct(private ManageExpenses $manage, private CurrencyScales $scales, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ExpenseResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::READ);

        try {
            $expense = $this->manage->get($company, CompanyPath::identifier($uriVariables, 'expenseId'));
        } catch (ExpenseNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return ExpenseResource::of($expense, $this->scales->of($company->getCurrency()), $this->manage->attachmentCount($expense), $this->manage->suggestedWithholdingRate($company, $expense));
    }
}
