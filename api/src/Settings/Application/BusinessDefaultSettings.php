<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * The business defaults a company sets once and its customers, products and documents override (docs/SPEC.md § 3
 * Settings): the parties chain for what a document says to its customer, the articles chain for what a product
 * starts with. The levels below the company are declared now and become writable as their screens arrive; tax
 * arithmetic and legal mentions are not here, they are preset data.
 */
final readonly class BusinessDefaultSettings implements DeclaresSettings
{
    public const string MODULE = 'core';

    public function settings(): iterable
    {
        $parties = [SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer, SettingLevel::Document];
        $articles = [SettingLevel::Company, SettingLevel::ProductCategory, SettingLevel::Product];

        yield new SettingDefinition('document.payment_terms_days', SettingType::Int, 30, SettingChain::Parties, $parties, 'settings.document.payment_terms_days', self::MODULE, min: 0, max: 365);
        yield new SettingDefinition('document.language', SettingType::Enum, 'fr', SettingChain::Parties, $parties, 'settings.document.language', self::MODULE, choices: ['fr', 'en']);
        yield new SettingDefinition('document.printed_notes', SettingType::Text, '', SettingChain::Parties, $parties, 'settings.document.printed_notes', self::MODULE, maxLength: 2000);

        yield new SettingDefinition('article.default_unit', SettingType::Text, 'C62', SettingChain::Articles, $articles, 'settings.article.default_unit', self::MODULE, pattern: '/^[A-Z0-9]{2,3}$/');
        yield new SettingDefinition('article.stock_tracking', SettingType::Bool, false, SettingChain::Articles, $articles, 'settings.article.stock_tracking', self::MODULE);
    }
}
