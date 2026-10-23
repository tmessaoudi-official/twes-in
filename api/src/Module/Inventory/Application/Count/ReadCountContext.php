<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\Count;

use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\Model\ProductHomeLocation;
use App\Module\Inventory\Domain\Repository\ProductHomeLocationRepository;
use App\Module\Inventory\Domain\Repository\StockMovementRepository;
use App\Module\Inventory\Domain\ValueObject\StockLevel;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * What a count of these products would change, read before it is recorded: every place and lot something moved in for
 * each of them, with what is there now, and the places each lives at. A count sets one place's stock and leaves the
 * others alone, so a person counting at the wrong shelf adds stock there without taking any from where it was; this is
 * what lets the screen say so first. A lot that ever held goods is listed even when it holds none, so a lot code typed
 * in a count is known to be new only when no movement ever named it.
 */
final readonly class ReadCountContext
{
    /** As many products as a count sheet asks about at once. */
    public const int MOST = 100;

    public function __construct(
        private StockMovementRepository $movements,
        private ProductRepository $products,
        private ProductHomeLocationRepository $homes,
        private KeepStock $stock,
    ) {
    }

    /**
     * @param list<Uuid> $productIds
     *
     * @return list<array{product: Product, levels: list<StockLevel>, homes: list<Uuid>}> in the order asked, each once;
     *                                                                                    another company's product, and
     *                                                                                    one whose stock is not kept, are
     *                                                                                    left out
     */
    public function of(Company $company, array $productIds): array
    {
        $found = [];
        foreach ($this->products->ofIdsInCompany($productIds, $company->getId()) as $product) {
            if ($this->stock->tracked($product)) {
                $found[$product->getId()->toRfc4122()] = $product;
            }
        }
        $kept = [];
        foreach ($productIds as $id) {
            if (isset($found[$id->toRfc4122()])) {
                $kept[$id->toRfc4122()] = $found[$id->toRfc4122()];
            }
        }
        $homes = $this->homes->ofProducts(array_map(static fn (Product $product): Uuid => $product->getId(), array_values($kept)), $company->getId());

        return array_map(fn (Product $product): array => [
            'product' => $product,
            'levels' => $this->movements->levelsOf($company->getId(), $product->getId()),
            'homes' => array_map(static fn (ProductHomeLocation $home): Uuid => $home->getLocation()->getId(), $homes[$product->getId()->toRfc4122()] ?? []),
        ], array_values($kept));
    }
}
