<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Module\Customers\Domain\Model\Customer;
use App\Settings\Application\DeclaresSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use App\Shared\Domain\ValueObject\ReceiverRule;

/**
 * The quotes module's settings, in the parties chain, so a customer group or a customer can differ from its company:
 * how many days a sent quote binds its price, whether it ends with the « Bon pour accord » block, on by default as
 * the delivery note's reception block is (docs/SPEC.md § 7, 2026-10-03 20:58), and whether an answer names who gave it.
 */
final readonly class QuoteSettings implements DeclaresSettings
{
    public const string VALIDITY_DAYS = 'quote.validity_days';
    public const string SIGNATURE_BLOCK = 'quote.signature_block';
    public const string RECEIVER = 'quote.receiver';
    public const int DEFAULT_VALIDITY_DAYS = 30;
    public const int MAX_VALIDITY_DAYS = 365;
    /** The module's key, as QuotesModule declares it. */
    private const string MODULE = 'quotes';

    public function settings(): iterable
    {
        $parties = [SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer];

        yield new SettingDefinition(self::VALIDITY_DAYS, SettingType::Int, self::DEFAULT_VALIDITY_DAYS, SettingChain::Parties, $parties, 'settings.quote.validity_days', self::MODULE, min: 1, max: self::MAX_VALIDITY_DAYS);
        yield new SettingDefinition(self::SIGNATURE_BLOCK, SettingType::Bool, true, SettingChain::Parties, $parties, 'settings.quote.signature_block', self::MODULE);
        // Who answered for the customer: named when the person recording the answer wishes, by default; a shop may
        // require it at every answer or switch it off.
        // The key and the choices are written out, as the labels gate reads them: ReceiverRule's cases.
        yield new SettingDefinition('quote.receiver', SettingType::Enum, 'optional', SettingChain::Parties, $parties, 'settings.quote.receiver', self::MODULE, choices: ['off', 'optional', 'required']);
    }

    /** Whether an answer from this customer names who gave it, as the customer, its group and its company say. */
    public static function receiverRule(ReadSetting $settings, Customer $customer): ReceiverRule
    {
        $context = new SettingContext($customer->getCompany(), customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());

        return ReceiverRule::from($settings->text($context, self::RECEIVER));
    }
}
