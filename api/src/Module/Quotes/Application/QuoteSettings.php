<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * The quotes module's settings, in the parties chain, so a customer group or a customer can differ from its company:
 * how many days a sent quote binds its price, and whether it ends with the « Bon pour accord » block, on by default as
 * the delivery note's reception block is (docs/SPEC.md § 7, 2026-10-03 20:58).
 */
final readonly class QuoteSettings implements DeclaresSettings
{
    public const string VALIDITY_DAYS = 'quote.validity_days';
    public const string SIGNATURE_BLOCK = 'quote.signature_block';
    public const int DEFAULT_VALIDITY_DAYS = 30;
    public const int MAX_VALIDITY_DAYS = 365;
    /** The module's key, as QuotesModule declares it. */
    private const string MODULE = 'quotes';

    public function settings(): iterable
    {
        $parties = [SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer];

        yield new SettingDefinition(self::VALIDITY_DAYS, SettingType::Int, self::DEFAULT_VALIDITY_DAYS, SettingChain::Parties, $parties, 'settings.quote.validity_days', self::MODULE, min: 1, max: self::MAX_VALIDITY_DAYS);
        yield new SettingDefinition(self::SIGNATURE_BLOCK, SettingType::Bool, true, SettingChain::Parties, $parties, 'settings.quote.signature_block', self::MODULE);
    }
}
