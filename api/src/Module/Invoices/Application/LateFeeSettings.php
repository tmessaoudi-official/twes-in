<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * The late fee (MON-15, CLI-14): off until the company turns it on, and charged only where the company wrote a tier,
 * because no amount or rate is sourced in law here. One tier per reminder stage, the first for the first stage: an
 * amount, or a share of what the invoice still owes written with « % »; an empty or zero tier charges nothing at its
 * stage. The tiers are separated by « ; » so that an amount may be written with a decimal comma.
 */
final readonly class LateFeeSettings implements DeclaresSettings
{
    public const string ENABLED = 'late_fees.enabled';
    public const string TIERS = 'late_fees.tiers';
    /** Up to five tiers, each empty, an amount or a share: « 5 ; 10,5 ; 2 % ». */
    public const string TIERS_PATTERN = '/^\s*(\d{1,9}([.,]\d{1,3})?\s*%?)?(\s*;\s*(\d{1,9}([.,]\d{1,3})?\s*%?)?){0,4}\s*$/';
    /** The module's key, as InvoicesModule declares it. */
    private const string MODULE = 'invoices';

    public function settings(): iterable
    {
        yield new SettingDefinition(self::ENABLED, SettingType::Bool, false, SettingChain::Parties, [SettingLevel::Company], 'settings.late_fees.enabled', self::MODULE);
        yield new SettingDefinition(self::TIERS, SettingType::Text, '', SettingChain::Parties, [SettingLevel::Company], 'settings.late_fees.tiers', self::MODULE, pattern: self::TIERS_PATTERN);
    }

    /**
     * What a late invoice owes at a stage, rounded to the currency, as a decimal string; null where the tiers charge
     * nothing at that stage.
     *
     * @param int    $stage     one for the calendar's first stage
     * @param string $amountDue what the invoice still owes, a share's base
     */
    public static function feeFor(string $tiers, int $stage, string $amountDue, int $scale): ?string
    {
        if ('' === trim($tiers) || 1 !== preg_match(self::TIERS_PATTERN, $tiers)) {
            return null;
        }
        $tier = trim(explode(';', $tiers)[$stage - 1] ?? '');
        if ('' === $tier) {
            return null;
        }
        $share = str_ends_with($tier, '%');
        $value = Decimal::of(str_replace(',', '.', trim(rtrim($tier, '%'))));
        $fee = Decimal::round($share ? Decimal::of($amountDue)->mul($value, Decimal::WORKING_SCALE)->div(100, Decimal::WORKING_SCALE) : $value, $scale);

        return $fee->compare(0) > 0 ? Decimal::format($fee, $scale) : null;
    }
}
