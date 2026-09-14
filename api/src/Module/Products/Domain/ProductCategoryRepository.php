<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use Symfony\Component\Uid\Uuid;

interface ProductCategoryRepository
{
    /** @return list<ProductCategory> one company's categories, by name */
    public function ofCompany(Uuid $companyId): array;

    /** Null for a category that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?ProductCategory;

    public function ofNameInCompany(string $name, Uuid $companyId): ?ProductCategory;

    /** How many categories sit directly under this one. */
    public function countChildren(Uuid $categoryId): int;

    public function save(ProductCategory $category): void;

    public function remove(ProductCategory $category): void;
}
