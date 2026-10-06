<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\CostOnReceive;
use App\Module\Inventory\Domain\RunningValue;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Products\Domain\Product;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Company;
use BcMath\Number;

/**
 * Reads what a receipt of a product would do to its cost, before it is saved: nothing is written. A quantity and a cost
 * that are not usable numbers are left out, and the average is then the current one.
 */
final readonly class ReadReceiptCost
{
    public function __construct(
        private StockMovementRepository $movements,
        private ReadSetting $settings,
    ) {
    }

    public function read(Company $company, Product $product, ?string $quantity, ?string $unitCost): ReceiptCost
    {
        $context = new SettingContext($company, productCategoryId: $product->getCategory()?->getId(), productId: $product->getId());
        $chosen = $this->settings->value($context, StockCostSettings::COST_ON_RECEIVE);
        $mode = (\is_string($chosen) ? CostOnReceive::tryFrom($chosen) : null) ?? CostOnReceive::Suggest;
        $own = $product->getDetails()->costPrice;
        $costNow = null !== $own && is_numeric($own) ? new Number($own)->round(4)->value : null;

        $stock = RunningValue::of($this->movements->valuedTotalsOf($product));
        if (null !== $quantity && null !== $unitCost && is_numeric($quantity) && is_numeric($unitCost)
            && 1 === new Number($quantity)->compare(0) && -1 !== new Number($unitCost)->compare(0)) {
            $stock = $stock->after($quantity, $unitCost);
        }
        $last = $this->movements->lastTypedCostOf($product);

        return new ReceiptCost($mode, $costNow, $stock->average($own), $last?->cost, $last?->at);
    }
}
