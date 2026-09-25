<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * The thresholds of what the invoices module puts on « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10), each a
 * company setting: a customer is watched once an invoice is this many days late, a product once it has not been sold
 * for this many days.
 */
final readonly class InvoiceWatchSettings implements DeclaresSettings
{
    public const string LATE_AFTER_DAYS = 'watch.late_after_days';
    public const string UNSOLD_AFTER_DAYS = 'watch.unsold_after_days';
    /** The module's key, as InvoicesModule declares it. */
    private const string MODULE = 'invoices';

    public function settings(): iterable
    {
        $company = [SettingLevel::Company];

        yield new SettingDefinition(self::LATE_AFTER_DAYS, SettingType::Int, 30, SettingChain::Parties, $company, 'settings.watch.late_after_days', self::MODULE, min: 1, max: 365);
        yield new SettingDefinition(self::UNSOLD_AFTER_DAYS, SettingType::Int, 90, SettingChain::Articles, $company, 'settings.watch.unsold_after_days', self::MODULE, min: 7, max: 730);
    }
}
