<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;

/**
 * Where the products file's switches start for a company: the screen starts each as set here, a file may still answer
 * otherwise for one run. Each default is what the file did before the setting existed. The keys and the choices are
 * written out because the label gate reads them from the source.
 */
final readonly class ProductImportSettings implements DeclaresSettings
{
    public const string UNPRICED_INACTIVE = 'import.products_unpriced_inactive';
    public const string BELOW_COST = 'import.products_below_cost';
    public const string EXCESS_DECIMALS = 'import.products_excess_decimals';
    private const string MODULE = 'products';

    public function settings(): iterable
    {
        $company = [SettingLevel::Company];
        yield new SettingDefinition('import.products_unpriced_inactive', SettingType::Bool, false, SettingChain::Articles, $company, 'settings.import.products_unpriced_inactive', self::MODULE);
        yield new SettingDefinition('import.products_below_cost', SettingType::Enum, 'note', SettingChain::Articles, $company, 'settings.import.products_below_cost', self::MODULE, choices: ['note', 'refuse']);
        yield new SettingDefinition('import.products_excess_decimals', SettingType::Enum, 'refuse', SettingChain::Articles, $company, 'settings.import.products_excess_decimals', self::MODULE, choices: ['refuse', 'round']);
    }
}
