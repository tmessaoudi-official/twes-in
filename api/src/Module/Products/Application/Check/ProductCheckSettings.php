<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application\Check;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The « À vérifier » reasons a company leaves out, among those saying a field is empty: none by default, so a product
 * says everything it lacks. A list of what is left out rather than of what is kept, so a reason a later release adds is
 * said by every company until it chooses otherwise. What an import's doubt leaves is never offered
 * (`ProductCheckReason::mayBeLeftOut`). The key and the choices are written out because the label gate reads them from
 * the source; `ProductCheckSettingsTest` holds them to the reasons. Declared last of its section.
 */
#[AsTaggedItem(priority: -230)]
final readonly class ProductCheckSettings implements DeclaresSettings
{
    public const string LEFT_OUT = 'article.check_left_out';
    private const string MODULE = 'products';

    public function settings(): iterable
    {
        yield new SettingDefinition('article.check_left_out', SettingType::Choices, [], SettingChain::Articles, [SettingLevel::Company], 'settings.article.check_left_out', self::MODULE, choices: ['price_missing', 'vendor_missing', 'vat_missing', 'cost_missing', 'barcode_missing', 'category_missing']);
    }
}
