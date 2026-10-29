<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ShortSales;

use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Inventory\Application\ShortSales\SaleWithoutStockSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\ShortSales\SaleStock;
use App\Shared\Application\ShortSales\Shortfall;
use App\Shared\Application\ShortSales\ShortSaleRule;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The stock module's answer to a sale: its rule for the seller's role at the establishment, and the products short
 * there, read only when the rule does not allow any sale.
 */
final readonly class InventorySaleStock implements SaleStock
{
    public function __construct(private ReadSetting $settings, private MoveStockForDeliveryNotes $move)
    {
    }

    public function shortfall(Company $company, Uuid $establishmentId, ?Uuid $roleId, array $lines): Shortfall
    {
        $rule = SaleWithoutStockSettings::ruleOf($this->settings->value(new SettingContext($company, $roleId, establishmentId: $establishmentId), SaleWithoutStockSettings::KEY));

        return ShortSaleRule::Allow === $rule ? new Shortfall($rule) : new Shortfall($rule, $this->move->shortOf($company->getId(), $establishmentId, $lines));
    }
}
