<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Domain\ProductSearch;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
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

    /** Narrows as the database does; sorts by reference only, which is all the unit tests ask for. */
    public function search(Uuid $companyId, ProductSearch $search, PageRequest $page): Page
    {
        $found = array_values(array_filter($this->ofCompany($companyId), static function (Product $p) use ($search): bool {
            $details = $p->getDetails();

            return InMemorySearch::finds($search->text, $p->getReference(), [$details->name, $details->barcode])
                && (null === $search->kind || $details->kind === $search->kind)
                && (null === $search->active || $p->isActive() === $search->active);
        }));

        return new Page(\array_slice($found, $page->offset(), $page->size), \count($found), $page);
    }

    public function pick(Uuid $companyId, string $words, int $limit, ?ProductKind $kind = null): array
    {
        $found = array_values(array_filter($this->ofCompany($companyId), static function (Product $p) use ($words, $kind): bool {
            $details = $p->getDetails();

            return $p->isActive()
                && (null === $kind || $details->kind === $kind)
                && InMemorySearch::finds($words, $p->getReference(), [$details->name, $details->barcode]);
        }));
        usort($found, static fn (Product $a, Product $b): int => $a->getReference() <=> $b->getReference());

        return \array_slice($found, 0, $limit);
    }

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids);

        return array_values(array_filter($this->ofCompany($companyId), static fn (Product $product): bool => \in_array($product->getId()->toRfc4122(), $wanted, true)));
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

    public function ofBarcodeInCompany(string $barcode, Uuid $companyId): ?Product
    {
        foreach ($this->ofCompany($companyId) as $product) {
            if ($product->getDetails()->barcode === $barcode) {
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
