<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application\Printing;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Whether whoever may not read costs may print an order to a vendor: refused, as by default, since its prices are costs,
 * or printed without them, its prices, totals and vendor codes left out and the page saying so. A cost reader prints
 * every price whatever is chosen. Declared after the delivery notes' row of its own, so the settings page draws it on a
 * row of its own too. The key and its choices are written out, as the label gate reads them from the source.
 */
#[AsTaggedItem(priority: -170)]
final readonly class PurchaseOrderPrintSettings implements DeclaresSettings
{
    public const string NON_COST_READERS = 'purchase_order.print_for_non_cost_readers';
    public const string REFUSED = 'refused';
    public const string WITHOUT_PRICES = 'without_prices';
    /** The module's key, as PurchasesModule declares it. */
    private const string MODULE = 'purchases';

    public function settings(): iterable
    {
        yield new SettingDefinition('purchase_order.print_for_non_cost_readers', SettingType::Enum, 'refused', SettingChain::Parties, [SettingLevel::Company], 'settings.purchase_order.print_for_non_cost_readers', self::MODULE, choices: ['refused', 'without_prices']);
    }

    /** Whether the company lets whoever may not read costs print its orders without their prices. */
    public static function printsWithoutPrices(ReadSetting $settings, Company $company): bool
    {
        return self::WITHOUT_PRICES === $settings->value(new SettingContext($company), self::NON_COST_READERS);
    }
}
