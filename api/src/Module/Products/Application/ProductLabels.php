<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductBarcode;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * What a sheet of labels prints for the products a person selected: each product once, in the order chosen, with every
 * code it holds and what a customer pays for what that code enters, taxes included, counted as a scan of the code
 * counts it. A product the company does not hold is left out; the sheet says so. Which code a label carries is the
 * sheet's choice, as on one product's own sheet.
 */
final readonly class ProductLabels
{
    /** A list page at most, which is what a selection holds. */
    public const int MOST = 100;

    public function __construct(private ProductRepository $products, private CustomerPrice $prices)
    {
    }

    /**
     * @param list<Uuid> $productIds
     *
     * @return list<array{productId: string, reference: string, name: string, codes: list<array{code: string, role: string, quantity: int, priceGross: string}>}>
     *
     * @throws \InvalidArgumentException when no product or more than a list page is asked for
     */
    public function of(Company $company, array $productIds): array
    {
        $unique = [];
        foreach ($productIds as $id) {
            $unique[$id->toRfc4122()] ??= $id;
        }
        if ([] === $unique || \count($unique) > self::MOST) {
            throw new \InvalidArgumentException(\sprintf('A sheet of labels takes 1 to %d products.', self::MOST));
        }
        $held = [];
        foreach ($this->products->ofIdsInCompany(array_values($unique), $company->getId()) as $product) {
            $held[$product->getId()->toRfc4122()] = $product;
        }
        $labels = [];
        foreach (array_keys($unique) as $id) {
            if (isset($held[$id])) {
                $labels[] = $this->label($held[$id]);
            }
        }

        return $labels;
    }

    /** @return array{productId: string, reference: string, name: string, codes: list<array{code: string, role: string, quantity: int, priceGross: string}>} */
    private function label(Product $product): array
    {
        return [
            'productId' => $product->getId()->toRfc4122(),
            'reference' => $product->getReference(),
            'name' => $product->getDetails()->name,
            'codes' => array_map(fn (ProductBarcode $barcode): array => [
                'code' => $barcode->getCode(),
                'role' => $barcode->getRole()->value,
                'quantity' => $barcode->getQuantity(),
                'priceGross' => $this->prices->of($product, $barcode->getQuantity()),
            ], $product->getBarcodes()),
        ];
    }
}
