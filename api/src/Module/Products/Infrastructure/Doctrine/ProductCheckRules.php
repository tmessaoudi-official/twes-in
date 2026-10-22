<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Doctrine;

use App\Module\Products\Domain\Model\ProductBarcode;
use App\Module\Products\Domain\Model\ProductVendor;
use App\Module\Products\Domain\ValueObject\ProductCheckReason;
use App\Module\Products\Domain\ValueObject\ProductCheckScope;
use App\Module\Products\Domain\ValueObject\ProductKind;

/**
 * `Product::checkReasons` said in DQL over the product `p`, for a list to narrow itself and « À surveiller » to count:
 * the one place the database reads them, which the functional `ProductCheckTest` holds to the entity's own answer product by product.
 */
final class ProductCheckRules
{
    /** One reason, for goods alone where only goods lack it. */
    public static function of(ProductCheckReason $reason): string
    {
        $condition = match ($reason) {
            ProductCheckReason::PriceMissing => 'p.unitPriceNet = 0 AND p.zeroPriceIntended = false',
            ProductCheckReason::VendorMissing => 'NOT EXISTS (SELECT check_vendor.id FROM '.ProductVendor::class.' check_vendor WHERE check_vendor.product = p)',
            ProductCheckReason::VatMissing => "p.defaultTaxComponentIds = '[]'",
            ProductCheckReason::CostMissing => 'p.costPrice IS NULL',
            ProductCheckReason::BarcodeMissing => 'NOT EXISTS (SELECT check_code.id FROM '.ProductBarcode::class.' check_code WHERE check_code.product = p)',
            ProductCheckReason::CategoryMissing => 'p.category IS NULL',
            ProductCheckReason::UnitToConfirm => 'p.unitToConfirm = true',
            ProductCheckReason::PriceNotReached => 'p.priceNotReached = true',
            ProductCheckReason::ToCount => 'p.toCount = true',
        };

        return $reason->onlyForGoods() ? \sprintf("(p.kind = '%s' AND %s)", ProductKind::Goods->value, $condition) : '('.$condition.')';
    }

    /**
     * Any of these reasons, or, when no reason is named, the mark a person set or any reason the scope says.
     *
     * @param list<ProductCheckReason> $reasons
     */
    public static function anyOf(ProductCheckScope $scope, array $reasons = []): string
    {
        $conditions = array_map(self::of(...), [] === $reasons ? $scope->reasons() : $reasons);

        return '('.implode(' OR ', [] === $reasons ? ['p.toCheck = true', ...$conditions] : $conditions).')';
    }
}
