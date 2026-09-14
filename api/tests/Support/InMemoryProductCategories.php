<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductCategoryRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryProductCategories implements ProductCategoryRepository
{
    /** @var list<ProductCategory> */
    public array $categories = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->categories, static fn (ProductCategory $c) => $c->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (ProductCategory $a, ProductCategory $b) => $a->getName() <=> $b->getName());

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?ProductCategory
    {
        foreach ($this->ofCompany($companyId) as $category) {
            if ($category->getId()->equals($id)) {
                return $category;
            }
        }

        return null;
    }

    public function ofNameInCompany(string $name, Uuid $companyId): ?ProductCategory
    {
        foreach ($this->ofCompany($companyId) as $category) {
            if ($category->getName() === $name) {
                return $category;
            }
        }

        return null;
    }

    public function countChildren(Uuid $categoryId): int
    {
        return \count(array_filter($this->categories, static fn (ProductCategory $c) => true === $c->getParent()?->getId()->equals($categoryId)));
    }

    public function save(ProductCategory $category): void
    {
        if (!\in_array($category, $this->categories, true)) {
            $this->categories[] = $category;
        }
    }

    public function remove(ProductCategory $category): void
    {
        $this->categories = array_values(array_filter($this->categories, static fn (ProductCategory $c) => $c !== $category));
    }
}
