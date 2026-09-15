<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Expenses\Application\AttachmentNotFound;
use App\Module\Expenses\Application\ExpenseNotFound;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Domain\ExpenseTransitionRefused;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<ExpenseAttachmentResource, null> */
final readonly class DetachExpenseAttachmentProcessor implements ProcessorInterface
{
    public function __construct(private ManageExpenses $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::WRITE);

        try {
            $this->manage->detach($company, CompanyPath::identifier($uriVariables, 'expenseId'), CompanyPath::identifier($uriVariables, 'attachmentId'), $this->guard->account()->getId());
        } catch (ExpenseNotFound|AttachmentNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (ExpenseTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        }

        return null;
    }
}
