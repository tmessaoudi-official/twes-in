<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Module\Products\Domain\BarcodeRole;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductPhotoRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * What the customer screen may show of a product: its name, its final tax-included price, our own reference, our own
 * barcode and its main photo, never another of its gallery. The row is that allow-list and nothing more, so a screen put in front of a customer cannot draw a cost,
 * a supplier's code or a quantity: the API never sent them. A product is found by a scan or by words, exactly as a
 * picker finds one, and only an active product is offered.
 */
final readonly class CustomerScreenProducts
{
    /** What the screen lists at once: a customer reads a few, not a catalogue. */
    public const int SHOWN = 8;

    public function __construct(private ProductRepository $products, private CustomerPrice $prices, private ProductPhotoRepository $photos)
    {
    }

    /** @return list<array{id: string, name: string, reference: string, barcode: string|null, finalPrice: string, photoId: string|null}> */
    public function matching(Company $company, string $words): array
    {
        $products = $this->products->pick($company->getId(), $words, self::SHOWN);
        $photos = [] === $products ? [] : $this->photos->mainPhotoIdsOf(
            $company->getId(),
            array_map(static fn (Product $product): Uuid => $product->getId(), $products),
        );

        return array_map(fn (Product $product): array => $this->row($product, $photos[$product->getId()->toRfc4122()] ?? null), $products);
    }

    /** @return array{id: string, name: string, reference: string, barcode: string|null, finalPrice: string, photoId: string|null} */
    private function row(Product $product, ?string $photoId): array
    {
        return [
            'id' => $product->getId()->toRfc4122(),
            'name' => $product->getDetails()->name,
            'reference' => $product->getReference(),
            'barcode' => self::ownBarcode($product),
            'finalPrice' => $this->prices->of($product, 1),
            'photoId' => $photoId,
        ];
    }

    /** The unit barcode, else an internal one: a supplier's code and a pack's are not what a customer reads on a shelf. */
    private static function ownBarcode(Product $product): ?string
    {
        foreach ([BarcodeRole::Unit, BarcodeRole::Internal] as $role) {
            foreach ($product->getBarcodes() as $barcode) {
                if ($barcode->getRole() === $role) {
                    return $barcode->getCode();
                }
            }
        }

        return null;
    }
}
