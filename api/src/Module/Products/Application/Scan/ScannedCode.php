<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application\Scan;

use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductBarcode;
use App\Module\Products\Domain\ValueObject\BarcodeRole;

/**
 * What a scan named: the product, the code as the company holds it, its role and how many pieces it enters. Most often
 * one of the product's barcodes; for loose goods with none, its reference, which their label prints instead and which
 * enters one piece.
 */
final readonly class ScannedCode
{
    private function __construct(
        public Product $product,
        public string $code,
        public BarcodeRole $role,
        public int $quantity,
    ) {
    }

    public static function barcode(ProductBarcode $held): self
    {
        return new self($held->getProduct(), $held->getCode(), $held->getRole(), $held->getQuantity());
    }

    public static function reference(Product $product): self
    {
        return new self($product, $product->getReference(), BarcodeRole::Unit, 1);
    }
}
