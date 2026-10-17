<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Inventory;

use App\Module\Inventory\Application\ProductsHeld;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Shared\Application\RecordVersions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Answers the inventory's `ProductsHeld` port: the product is held and checked as its own revision holds and checks it. */
final readonly class ProductHeldForInventory implements ProductsHeld
{
    public function __construct(private ProductRepository $products, private RecordVersions $versions, private ClockInterface $clock)
    {
    }

    public function held(Company $company, Uuid $productId, ?int $version): ?Product
    {
        $product = $this->products->lockedOfIdInCompany($productId, $company->getId());
        if (null !== $product && null !== $version) {
            $this->versions->expect($product, $version);
        }

        return $product;
    }

    public function changedBeside(Product $product): void
    {
        $product->touched($this->clock->now());
        $this->products->save($product);
    }
}
