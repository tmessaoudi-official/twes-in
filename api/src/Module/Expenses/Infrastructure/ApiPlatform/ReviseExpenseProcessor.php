<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Module\Expenses\Application\ExpenseNotFound;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Domain\ExpenseTransitionRefused;
use App\Module\Expenses\Domain\InvalidExpense;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<ExpenseResource, ExpenseResource> */
final readonly class ReviseExpenseProcessor implements ProcessorInterface
{
    public function __construct(private ManageExpenses $manage, private CurrencyScales $scales, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::WRITE);

        try {
            $expense = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'expenseId'), $data->input(), $this->guard->account()->getId());
        } catch (ExpenseNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (ExpenseTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidExpense $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return ExpenseResource::of($expense, $this->scales->of($company->getCurrency()), $this->manage->attachmentCount($expense));
    }
}
