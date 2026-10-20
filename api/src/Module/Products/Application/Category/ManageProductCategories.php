<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application\Category;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Products\Domain\Exception\InvalidProductCategory;
use App\Module\Products\Domain\Model\ProductCategory;
use App\Module\Products\Domain\Repository\ProductCategoryRepository;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's product categories: listed by name, each name used once in the company, placed under another of its
 * categories or at the top. Deleting one is the records engine's, which keeps it a day to put back. Audited with the
 * names of the fields a revision changed, never their values.
 */
final readonly class ManageProductCategories
{
    public const string ENTITY_TYPE = 'product_category';
    public const string CREATED = 'product_category.created';
    public const string REVISED = 'product_category.revised';

    public function __construct(
        private ProductCategoryRepository $categories,
        private ProductRepository $products,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private RecordVersions $versions,
    ) {
    }

    /** @return list<ProductCategory> */
    public function list(Company $company): array
    {
        return $this->categories->ofCompany($company->getId());
    }

    /** How many products, active or not, the category holds directly. */
    public function productCount(ProductCategory $category): int
    {
        return $this->products->countInCategory($category->getId());
    }

    /** How many categories sit directly under this one. */
    public function childCount(ProductCategory $category): int
    {
        return $this->categories->countChildren($category->getId());
    }

    /**
     * @throws ProductCategoryNameTaken
     * @throws InvalidProductCategory
     */
    public function create(Company $company, string $name, ?Uuid $parentId, ?Uuid $actorUserId): ProductCategory
    {
        return $this->transactions->run(function () use ($company, $name, $parentId, $actorUserId): ProductCategory {
            if (null !== $this->categories->ofNameInCompany(trim($name), $company->getId())) {
                throw new ProductCategoryNameTaken();
            }
            $category = ProductCategory::create($company, $name, $this->parent($company, $parentId), $this->clock->now());
            $this->categories->save($category);
            $this->record($company, $category->getId(), self::CREATED, [], $actorUserId);

            return $category;
        });
    }

    /**
     * @throws ProductCategoryNotFound
     * @throws ProductCategoryNameTaken
     * @throws InvalidProductCategory
     * @throws RecordChanged
     */
    public function revise(Company $company, Uuid $id, string $name, ?Uuid $parentId, ?Uuid $actorUserId, ?int $version = null): ProductCategory
    {
        return $this->transactions->run(function () use ($company, $id, $name, $parentId, $actorUserId, $version): ProductCategory {
            $category = $this->find($company, $id);
            if (null !== $version) {
                $this->versions->expect($category, $version);
            }
            $holder = $this->categories->ofNameInCompany(trim($name), $company->getId());
            if (null !== $holder && !$holder->getId()->equals($category->getId())) {
                throw new ProductCategoryNameTaken();
            }

            $changed = $category->revise($name, $this->parent($company, $parentId), $this->clock->now());
            if ([] !== $changed) {
                $this->categories->save($category);
                $this->record($company, $category->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $category;
        });
    }

    private function parent(Company $company, ?Uuid $parentId): ?ProductCategory
    {
        if (null === $parentId) {
            return null;
        }

        return $this->categories->ofIdInCompany($parentId, $company->getId())
            ?? throw new InvalidProductCategory('parentId', 'No product category of this company has this id.');
    }

    private function find(Company $company, Uuid $id): ProductCategory
    {
        return $this->categories->ofIdInCompany($id, $company->getId()) ?? throw new ProductCategoryNotFound();
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $categoryId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $categoryId, $action, $actorUserId, $changes, $company->getId()));
    }
}
