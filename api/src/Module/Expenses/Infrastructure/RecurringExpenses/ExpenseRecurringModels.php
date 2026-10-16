<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\RecurringExpenses;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Expenses\Domain\Model\Expense;
use App\Module\RecurringExpenses\Application\RecurringExpenseModel;
use App\Module\RecurringExpenses\Application\RecurringExpenseModels;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** What a recurring expense shows of the expense it copies: what was bought, from whom, and what it came to. */
final readonly class ExpenseRecurringModels implements RecurringExpenseModels
{
    public function __construct(private EntityManagerInterface $entityManager, private CurrencyScales $scales)
    {
    }

    public function describe(Company $company, Uuid $expenseId): ?RecurringExpenseModel
    {
        return $this->describeAll($company, [$expenseId])[$expenseId->toRfc4122()] ?? null;
    }

    public function describeAll(Company $company, array $expenseIds): array
    {
        if ([] === $expenseIds) {
            return [];
        }
        $found = $this->entityManager->createQueryBuilder()
            ->select('e')->from(Expense::class, 'e')
            ->where('e.company = :company')->andWhere('e.id IN (:ids)')
            ->setParameter('company', $company->getId(), 'uuid')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $expenseIds))
            ->getQuery()->getResult();
        $models = [];
        foreach (\is_array($found) ? $found : [] as $expense) {
            if (!$expense instanceof Expense) {
                continue;
            }
            $models[$expense->getId()->toRfc4122()] = new RecurringExpenseModel(
                $expense->getDescription(),
                $expense->getVendor()?->getProfile()->name ?? $expense->getPayee(),
                Decimal::format(Decimal::of($expense->getAmountGross()), $this->scales->of($expense->getCurrency())),
                $expense->getCurrency(),
            );
        }

        return $models;
    }
}
