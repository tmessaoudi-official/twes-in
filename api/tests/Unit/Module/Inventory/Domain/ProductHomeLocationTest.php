<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Inventory\Domain;

use App\Fiscal\Domain\Model\Unit;
use App\Module\Inventory\Domain\Exception\InvalidStockLocation;
use App\Module\Inventory\Domain\Model\ProductHomeLocation;
use App\Module\Inventory\Domain\Model\StockLocation;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use PHPUnit\Framework\TestCase;

/** A product's home is one of its own company's places, and a place of another company is refused as not the company's. */
final class ProductHomeLocationTest extends TestCase
{
    public function testAHomeAmongAnotherCompanysPlacesIsRefusedAsNotTheCompanys(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 09:00:00');
        $acme = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis', $now);
        $other = new Company('Autre', 'TN', 'TND', 'fr', 'Africa/Tunis', $now);
        $product = Product::create($acme, 'ART-001', new ProductDetails('Souris', null, ProductKind::Goods, '40'), Unit::create($acme, 'C62', 'Pièce', 0, 0, $now), null, [], $now);
        $elsewhere = StockLocation::defaultOf(Establishment::create($other, '000', 'Siège', true, $now), $now);

        try {
            ProductHomeLocation::at($product, $elsewhere, 0, $now);
            self::fail('A product was given a home among another company\'s places.');
        } catch (InvalidStockLocation $refused) {
            self::assertSame(['productId', 'invalid_location'], [$refused->field, $refused->reason]);
        }
    }
}
