<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Purchases;

use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductBarcode;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Domain\ValueObject\BarcodeRole;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Purchases\Application\OrderProduct;
use App\Module\Purchases\Application\OrderProducts;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** Answers the purchases module's `OrderProducts` port out of this module: only goods are offered to order. */
final readonly class ProductsForOrders implements OrderProducts
{
    /** As many as a picker shows, the same as every other picker. */
    private const int SHOWN = 20;

    public function __construct(private ProductRepository $products)
    {
    }

    public function matching(Company $company, string $words): array
    {
        return array_map(self::product(...), $this->products->pick($company->getId(), $words, self::SHOWN, ProductKind::Goods));
    }

    public function ofIds(Company $company, array $ids): array
    {
        return array_map(self::product(...), $this->products->ofIdsInCompany($ids, $company->getId()));
    }

    public function vendorCodes(Company $company, Uuid $vendorId, array $ids): array
    {
        $codes = [];
        foreach ($this->products->ofIdsInCompany($ids, $company->getId()) as $product) {
            $code = array_find($product->getBarcodes(), static fn (ProductBarcode $code): bool => BarcodeRole::Supplier === $code->getRole()
                && 1 === $code->getQuantity()
                && true === $code->getSupplier()?->getId()->equals($vendorId));
            if (null !== $code) {
                $codes[$product->getId()->toRfc4122()] = $code->getCode();
            }
        }

        return $codes;
    }

    private static function product(Product $product): OrderProduct
    {
        $details = $product->getDetails();

        return new OrderProduct(
            $product->getId(),
            $product->getReference(),
            $details->name,
            $product->getUnit()->getId(),
            $product->getUnit()->getName(),
            ProductKind::Goods === $details->kind,
            $product->isActive(),
            $product->getTracking()->value,
            $details->costPrice,
            $product->getMergedInto()?->getReference(),
        );
    }
}
