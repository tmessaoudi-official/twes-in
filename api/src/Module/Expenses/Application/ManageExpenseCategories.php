<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Expenses\Domain\ExpenseCategory;
use App\Module\Expenses\Domain\ExpenseCategoryRepository;
use App\Module\Expenses\Domain\InvalidExpense;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's expense categories: listed by name, each name used once in the company, placed under another of its
 * categories or at the top, deactivated rather than deleted because expenses keep them. Audited with the names of the
 * fields a revision changed.
 */
final readonly class ManageExpenseCategories
{
    public const string ENTITY_TYPE = 'expense_category';
    public const string CREATED = 'expense_category.created';
    public const string REVISED = 'expense_category.revised';

    public function __construct(
        private ExpenseCategoryRepository $categories,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /** @return list<ExpenseCategory> */
    public function list(Company $company): array
    {
        return $this->categories->ofCompany($company->getId());
    }

    /**
     * @throws ExpenseCategoryNameTaken
     * @throws InvalidExpense
     */
    public function create(Company $company, string $name, ?Uuid $parentId, bool $isActive, ?Uuid $actorUserId): ExpenseCategory
    {
        return $this->transactions->run(function () use ($company, $name, $parentId, $isActive, $actorUserId): ExpenseCategory {
            if (null !== $this->categories->ofNameInCompany(trim($name), $company->getId())) {
                throw new ExpenseCategoryNameTaken();
            }
            $category = ExpenseCategory::create($company, $name, $this->parent($company, $parentId), $this->clock->now());
            if (!$isActive) {
                $category->revise($name, $category->getParent(), false, $this->clock->now());
            }
            $this->categories->save($category);
            $this->record($company, $category->getId(), self::CREATED, [], $actorUserId);

            return $category;
        });
    }

    /**
     * @throws ExpenseCategoryNotFound
     * @throws ExpenseCategoryNameTaken
     * @throws InvalidExpense
     */
    public function revise(Company $company, Uuid $id, string $name, ?Uuid $parentId, bool $isActive, ?Uuid $actorUserId): ExpenseCategory
    {
        return $this->transactions->run(function () use ($company, $id, $name, $parentId, $isActive, $actorUserId): ExpenseCategory {
            $category = $this->categories->ofIdInCompany($id, $company->getId()) ?? throw new ExpenseCategoryNotFound();
            $holder = $this->categories->ofNameInCompany(trim($name), $company->getId());
            if (null !== $holder && !$holder->getId()->equals($category->getId())) {
                throw new ExpenseCategoryNameTaken();
            }

            $changed = $category->revise($name, $this->parent($company, $parentId), $isActive, $this->clock->now());
            if ([] !== $changed) {
                $this->categories->save($category);
                $this->record($company, $category->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $category;
        });
    }

    private function parent(Company $company, ?Uuid $parentId): ?ExpenseCategory
    {
        if (null === $parentId) {
            return null;
        }

        return $this->categories->ofIdInCompany($parentId, $company->getId())
            ?? throw new InvalidExpense('parentId', 'No expense category of this company has this id.');
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $categoryId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $categoryId, $action, $actorUserId, $changes, $company->getId()));
    }
}
