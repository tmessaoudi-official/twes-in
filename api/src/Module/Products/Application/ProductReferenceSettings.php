<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Module\Products\Domain\ReferenceFormat;
use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * How a product left without a reference is given one: the company's format, which a category may write its own way
 * (BOI-{SEQ:4} for its bolts) while the number still comes from the company's one counter.
 */
final readonly class ProductReferenceSettings implements DeclaresSettings
{
    public const string FORMAT = 'article.reference_format';
    public const string DEFAULT_FORMAT = 'ART-{SEQ:5}';
    private const string MODULE = 'products';

    public function settings(): iterable
    {
        yield new SettingDefinition(self::FORMAT, SettingType::Text, self::DEFAULT_FORMAT, SettingChain::Articles, [SettingLevel::Company, SettingLevel::ProductCategory], 'settings.article.reference_format', self::MODULE, maxLength: ReferenceFormat::MAX_LENGTH, pattern: ReferenceFormat::PATTERN);
    }
}
