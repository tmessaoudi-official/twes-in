<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use Symfony\Component\Uid\Uuid;

interface ProductRepository
{
    /** @return list<Product> one company's products, by reference */
    public function ofCompany(Uuid $companyId): array;

    /** Null for a product that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Product;

    public function ofReferenceInCompany(string $reference, Uuid $companyId): ?Product;

    /** How many products, active or not, the category holds directly. */
    public function countInCategory(Uuid $categoryId): int;

    public function save(Product $product): void;
}
