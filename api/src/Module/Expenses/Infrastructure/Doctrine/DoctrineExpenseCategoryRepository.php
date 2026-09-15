<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Doctrine;

use App\Module\Expenses\Domain\ExpenseCategory;
use App\Module\Expenses\Domain\ExpenseCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineExpenseCategoryRepository implements ExpenseCategoryRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        /** @var list<ExpenseCategory> $categories */
        $categories = $this->entityManager->getRepository(ExpenseCategory::class)->findBy(['company' => $companyId], ['name' => 'ASC']);

        return $categories;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?ExpenseCategory
    {
        $category = $this->entityManager->find(ExpenseCategory::class, $id);

        return null !== $category && $category->getCompany()->getId()->equals($companyId) ? $category : null;
    }

    public function ofNameInCompany(string $name, Uuid $companyId): ?ExpenseCategory
    {
        return $this->entityManager->getRepository(ExpenseCategory::class)->findOneBy(['company' => $companyId, 'name' => $name]);
    }

    public function save(ExpenseCategory $category): void
    {
        $this->entityManager->persist($category);
        $this->entityManager->flush();
    }
}
