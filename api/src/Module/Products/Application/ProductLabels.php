<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Module\Products\Application\Scan\ScannedCode;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Domain\ValueObject\BarcodeRole;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * What a sheet of labels prints for the products a person selected: each product once, in the order chosen, with every
 * code it holds and what a customer pays for what that code enters, taxes included, counted as a scan of the code
 * counts it, and the reference for a product holding no code of its own to print. A product the company does not hold is
 * left out; the sheet says so. Which code a label carries is the
 * sheet's choice, as on one product's own sheet.
 */
final readonly class ProductLabels
{
    /** A list page at most, which is what a selection holds. */
    public const int MOST = 100;
    /** The codes of its own a product's label may carry, as the sheet picks them: never a supplier's. */
    private const array PRINTED = [BarcodeRole::Unit, BarcodeRole::Internal, BarcodeRole::Pack];

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
        $codes = array_map(ScannedCode::barcode(...), $product->getBarcodes());
        // Loose goods hold no code of their own to print (a supplier's is theirs): the label carries the reference,
        // which a scan matching no barcode finds.
        $own = array_filter($codes, static fn (ScannedCode $code): bool => \in_array($code->role, self::PRINTED, true));
        if ([] === $own) {
            $codes[] = ScannedCode::reference($product);
        }

        return [
            'productId' => $product->getId()->toRfc4122(),
            'reference' => $product->getReference(),
            'name' => $product->getDetails()->name,
            'codes' => array_map(fn (ScannedCode $code): array => [
                'code' => $code->code,
                'role' => $code->role->value,
                'quantity' => $code->quantity,
                'priceGross' => $this->prices->of($product, $code->quantity),
            ], $codes),
        ];
    }
}
