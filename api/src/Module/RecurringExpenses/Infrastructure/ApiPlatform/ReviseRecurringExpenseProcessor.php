<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\RecurringExpenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Expenses\Infrastructure\ApiPlatform\ExpensePermission;
use App\Module\RecurringExpenses\Application\ManageRecurringExpenses;
use App\Module\RecurringExpenses\Application\RecurringExpenseNotFound;
use App\Shared\Domain\InvalidSchedule;
use App\Shared\Infrastructure\ApiPlatform\DomainRule;
use App\Shared\Infrastructure\ApiPlatform\RequestedSchedule;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<RecurringExpenseResource, RecurringExpenseResource> */
final readonly class ReviseRecurringExpenseProcessor implements ProcessorInterface
{
    public function __construct(private ManageRecurringExpenses $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RecurringExpenseResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::WRITE);

        try {
            $recurring = $this->manage->revise(
                $company,
                CompanyPath::identifier($uriVariables, 'recurringExpenseId'),
                RequestedSchedule::of($data),
                RequestedSchedule::dayOrNull($data->endsOn),
                $data->paused,
                $data->variableAmount,
                $this->guard->account()->getId(),
            );
        } catch (RecurringExpenseNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (InvalidSchedule $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason, previous: $refused);
        }

        return RecurringExpenseResource::of($recurring, $this->manage->models($company, [$recurring])[$recurring->getModelExpenseId()->toRfc4122()] ?? null);
    }
}
