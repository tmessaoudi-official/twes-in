<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Module\Expenses\Application\ExpenseNotFound;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Domain\Exception\ExpenseTransitionRefused;
use App\Module\Expenses\Domain\Exception\InvalidExpense;
use App\Module\Expenses\Domain\ValueObject\TejOperationCode;
use App\Module\Expenses\Infrastructure\ApiPlatform\Resource\ExpenseResource;
use App\Module\Expenses\Infrastructure\ApiPlatform\Security\ExpensePermission;
use App\Shared\Domain\PaymentMethod;
use App\Shared\Infrastructure\ApiPlatform\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<ExpenseResource, ExpenseResource> */
final readonly class PayExpenseProcessor implements ProcessorInterface
{
    public function __construct(private ManageExpenses $manage, private CurrencyScales $scales, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ExpensePermission::WRITE);

        try {
            $expense = $this->manage->pay(
                $company,
                CompanyPath::identifier($uriVariables, 'expenseId'),
                PaymentMethod::from((string) $data->paymentMethod),
                new \DateTimeImmutable((string) $data->paidOn, new \DateTimeZone('UTC')),
                $this->guard->account()->getId(),
                $data->withholdingRate,
                null === $data->withholdingOperationCode ? null : TejOperationCode::from($data->withholdingOperationCode),
            );
        } catch (ExpenseNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (ExpenseTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidExpense $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return ExpenseResource::of($expense, $this->scales->of($company->getCurrency()), $this->manage->attachmentCount($expense));
    }
}
