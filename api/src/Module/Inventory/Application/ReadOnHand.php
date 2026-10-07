<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductRepository;
use App\Tenancy\Application\Establishment\EstablishmentNotFound;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\Uid\Uuid;

/**
 * What one establishment's shelves hold of a few products, for the goods whose stock the company keeps: the sum of the
 * movements at its locations, never the whole company's, so a shop is not told of goods in another town. Asked
 * without an establishment, it is the main one's.
 */
final readonly class ReadOnHand
{
    public function __construct(
        private StockMovementRepository $movements,
        private ProductRepository $products,
        private KeepStock $stock,
        private EstablishmentRepository $establishments,
    ) {
    }

    /**
     * @param list<Uuid> $productIds
     *
     * @return list<array{product: Product, onHand: numeric-string}> in the order the products were found; another company's
     *                                                               product, and one whose stock is not kept, are left out
     */
    public function at(Company $company, Uuid $establishmentId, array $productIds): array
    {
        $kept = array_values(array_filter($this->products->ofIdsInCompany($productIds, $company->getId()), $this->stock->tracked(...)));
        $totals = $this->movements->totalsOf($company->getId(), array_map(static fn (Product $product): Uuid => $product->getId(), $kept), $establishmentId);

        return array_map(static fn (Product $product): array => ['product' => $product, 'onHand' => $totals[$product->getId()->toRfc4122()] ?? '0.000'], $kept);
    }

    /**
     * The establishment named, checked to be the company's, or the main one when none is.
     *
     * @throws EstablishmentNotFound when the establishment named is not the company's
     */
    public function establishment(Company $company, ?Uuid $establishmentId): Uuid
    {
        if (null !== $establishmentId) {
            return $this->establishments->ofIdInCompany($establishmentId, $company->getId())?->getId()
                ?? throw new EstablishmentNotFound('No such establishment.');
        }
        foreach ($this->establishments->ofCompany($company->getId()) as $establishment) {
            if ($establishment->isDefault()) {
                return $establishment->getId();
            }
        }

        throw new \LogicException('A company always has its main establishment.');
    }
}
