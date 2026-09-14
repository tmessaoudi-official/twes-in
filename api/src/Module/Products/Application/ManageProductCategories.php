<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Products\Domain\InvalidProductCategory;
use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductCategoryRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Settings\Application\ForgetSettings;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's product categories: listed by name, each name used once in the company, placed under another of its
 * categories or at the top, and deleted only once they hold no subcategory and no product, taking the defaults set
 * for them along. Audited with the names of the fields a revision changed, never their values.
 */
final readonly class ManageProductCategories
{
    public const string ENTITY_TYPE = 'product_category';
    public const string CREATED = 'product_category.created';
    public const string REVISED = 'product_category.revised';
    public const string DELETED = 'product_category.deleted';

    public function __construct(
        private ProductCategoryRepository $categories,
        private ProductRepository $products,
        private ForgetSettings $settings,
        private AuditTrail $audit,
        private ClockInterface $clock,
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
        if (null !== $this->categories->ofNameInCompany(trim($name), $company->getId())) {
            throw new ProductCategoryNameTaken();
        }
        $category = ProductCategory::create($company, $name, $this->parent($company, $parentId), $this->clock->now());
        $this->categories->save($category);
        $this->record($company, $category->getId(), self::CREATED, [], $actorUserId);

        return $category;
    }

    /**
     * @throws ProductCategoryNotFound
     * @throws ProductCategoryNameTaken
     * @throws InvalidProductCategory
     */
    public function revise(Company $company, Uuid $id, string $name, ?Uuid $parentId, ?Uuid $actorUserId): ProductCategory
    {
        $category = $this->find($company, $id);
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
    }

    /**
     * @throws ProductCategoryNotFound
     * @throws ProductCategoryInUse
     */
    public function delete(Company $company, Uuid $id, ?Uuid $actorUserId): void
    {
        $category = $this->find($company, $id);
        $subcategories = $this->childCount($category);
        $products = $this->productCount($category);
        if ($subcategories > 0 || $products > 0) {
            throw new ProductCategoryInUse($subcategories, $products);
        }
        $this->settings->at(SettingAddress::productCategory($company, $id));
        $this->categories->remove($category);
        $this->record($company, $id, self::DELETED, [], $actorUserId);
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
