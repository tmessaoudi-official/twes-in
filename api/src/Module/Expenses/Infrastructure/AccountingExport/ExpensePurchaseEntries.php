<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\AccountingExport;

use App\Module\AccountingExport\Application\DocumentTax;
use App\Module\AccountingExport\Application\JournalPeriod;
use App\Module\AccountingExport\Application\PurchaseEntries;
use App\Module\AccountingExport\Application\PurchaseEntry;
use App\Module\Expenses\Domain\Model\Expense;
use App\Module\Expenses\Domain\Model\ExpenseTax;
use App\Module\Expenses\Domain\ValueObject\ExpenseStatus;
use App\Tenancy\Domain\Model\Company;
use Doctrine\ORM\EntityManagerInterface;

/** The purchases journal's entries: every expense entered in the books, recorded or paid, on its own day. */
final readonly class ExpensePurchaseEntries implements PurchaseEntries
{
    private const int BATCH = 200;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function enteredIn(Company $company, JournalPeriod $period): iterable
    {
        for ($offset = 0;; $offset += self::BATCH) {
            $found = $this->entityManager->createQueryBuilder()
                ->select('e')->from(Expense::class, 'e')
                ->where('e.company = :company')->andWhere('e.status IN (:booked)')->andWhere('e.date BETWEEN :from AND :to')
                ->setParameter('company', $company->getId(), 'uuid')
                ->setParameter('booked', [ExpenseStatus::Recorded->value, ExpenseStatus::Paid->value])
                ->setParameter('from', $period->from->format('Y-m-d'))->setParameter('to', $period->to->format('Y-m-d'))
                ->orderBy('e.date')->addOrderBy('e.createdAt')->addOrderBy('e.id')
                ->setFirstResult($offset)->setMaxResults(self::BATCH)
                ->getQuery()->getResult();
            $expenses = array_values(array_filter(\is_array($found) ? $found : [], static fn (mixed $row): bool => $row instanceof Expense));
            foreach ($expenses as $expense) {
                yield new PurchaseEntry(
                    $expense->getDate(),
                    $expense->getReference() ?? '',
                    $expense->getVendor()?->getProfile()->name ?? $expense->getPayee() ?? '',
                    $expense->getDescription(),
                    $expense->getCategory()?->getName() ?? '',
                    array_map(static fn (ExpenseTax $tax): DocumentTax => new DocumentTax($tax->getCode(), $tax->getRate(), $tax->getBase(), $tax->getAmount()), $expense->getTaxes()),
                    $expense->getAmountNet(),
                    $expense->getTaxAmount(),
                    $expense->getAmountGross(),
                    $expense->getWithholdingAmount() ?? '',
                    $expense->getStatus()->value,
                    $expense->getPaidOn(),
                    $expense->getPaymentMethod()->value ?? '',
                );
            }
            if (\count($expenses) < self::BATCH) {
                return;
            }
        }
    }
}
