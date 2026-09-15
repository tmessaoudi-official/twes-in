<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Expenses\Application\ExpenseCategoryNameTaken;
use App\Module\Expenses\Application\ExpenseCategoryNotFound;
use App\Module\Expenses\Application\ManageExpenseCategories;
use App\Module\Expenses\Domain\InvalidExpense;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<ExpenseCategoryResource, ExpenseCategoryResource> */
final readonly class ReviseExpenseCategoryProcessor implements ProcessorInterface
{
    public function __construct(private ManageExpenseCategories $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseCategoryResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::WRITE);

        try {
            $category = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'categoryId'), $data->name, null === $data->parentId ? null : Uuid::fromString($data->parentId), $data->isActive, $this->guard->account()->getId());
        } catch (ExpenseCategoryNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (ExpenseCategoryNameTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidExpense $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return ExpenseCategoryResource::of($category);
    }
}
