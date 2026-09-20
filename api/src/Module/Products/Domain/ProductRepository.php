<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

interface ProductRepository
{
    /**
     * @param list<Uuid> $ids
     *
     * @return list<Product> those of the company among these ids, in no particular order
     */
    public function ofIdsInCompany(array $ids, Uuid $companyId): array;

    /** @return list<Product> one company's products, by reference */
    public function ofCompany(Uuid $companyId): array;

    /** @return Page<Product> one page of the company's products that the search finds, in its order */
    public function search(Uuid $companyId, ProductSearch $search, PageRequest $page): Page;

    /**
     * The first few ACTIVE products a person picking one in a form would mean, by reference. It searches on the same
     * expression the list does, so the same index serves it, and it counts nothing: a picker asks again on every
     * keystroke, and a total over a large catalogue is the cost that buys nothing here (docs/SPEC.md § 7, 2026-09-17).
     *
     * `$kind` narrows to one kind of product. It exists for the stock picker, which offers only goods: whether stock
     * is KEPT of a product also depends on a setting, which no WHERE clause can express, so the caller finishes the
     * filtering itself — this is the half that can be done in the database, and doing it here is what keeps the
     * caller's scan short enough to be honest about.
     *
     * @return list<Product>
     */
    public function pick(Uuid $companyId, string $words, int $limit, ?ProductKind $kind = null): array;

    /** Null for a product that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Product;

    public function ofReferenceInCompany(string $reference, Uuid $companyId): ?Product;

    /** How many products, active or not, the category holds directly. */
    public function countInCategory(Uuid $categoryId): int;

    public function save(Product $product): void;
}
