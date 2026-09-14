<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryProducts implements ProductRepository
{
    /** @var list<Product> */
    public array $products = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->products, static fn (Product $p) => $p->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (Product $a, Product $b) => $a->getReference() <=> $b->getReference());

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Product
    {
        foreach ($this->ofCompany($companyId) as $product) {
            if ($product->getId()->equals($id)) {
                return $product;
            }
        }

        return null;
    }

    public function ofReferenceInCompany(string $reference, Uuid $companyId): ?Product
    {
        foreach ($this->ofCompany($companyId) as $product) {
            if ($product->getReference() === $reference) {
                return $product;
            }
        }

        return null;
    }

    public function countInCategory(Uuid $categoryId): int
    {
        return \count(array_filter($this->products, static fn (Product $p) => true === $p->getCategory()?->getId()->equals($categoryId)));
    }

    public function save(Product $product): void
    {
        if (!\in_array($product, $this->products, true)) {
            $this->products[] = $product;
        }
    }
}
