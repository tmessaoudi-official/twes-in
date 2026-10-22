<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain\ValueObject;

/**
 * What a product lacks for « À vérifier », in the order a screen lists them: each clears itself once the product has it
 * (docs/SPEC.md § 7, the marker's reasons). The value is what the API sends and the screens translate.
 */
enum ProductCheckReason: string
{
    case PriceMissing = 'price_missing';
    case PriceNotReached = 'price_not_reached';
    case VendorMissing = 'vendor_missing';
    case VatMissing = 'vat_missing';
    case CostMissing = 'cost_missing';
    case BarcodeMissing = 'barcode_missing';
    case CategoryMissing = 'category_missing';
    case UnitToConfirm = 'unit_to_confirm';
    case ToCount = 'to_count';

    /** Goods are bought, scanned and counted; a service is none of these. */
    public function onlyForGoods(): bool
    {
        return \in_array($this, [self::VendorMissing, self::CostMissing, self::BarcodeMissing, self::ToCount], true);
    }
}
