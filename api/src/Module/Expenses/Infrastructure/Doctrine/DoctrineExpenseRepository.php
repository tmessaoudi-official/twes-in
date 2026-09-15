<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Doctrine;

use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineExpenseRepository implements ExpenseRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        /** @var list<Expense> $expenses */
        $expenses = $this->entityManager->getRepository(Expense::class)->findBy(['company' => $companyId], ['date' => 'DESC', 'createdAt' => 'DESC']);

        return $expenses;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Expense
    {
        $expense = $this->entityManager->find(Expense::class, $id);

        return null !== $expense && $expense->getCompany()->getId()->equals($companyId) ? $expense : null;
    }

    public function save(Expense $expense): void
    {
        $this->entityManager->persist($expense);
        $this->entityManager->flush();
    }

    public function remove(Expense $expense): void
    {
        $this->entityManager->remove($expense);
        $this->entityManager->flush();
    }
}
