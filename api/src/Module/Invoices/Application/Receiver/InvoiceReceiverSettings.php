<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Receiver;

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
 * Whether an invoice handing goods over itself names who received them, in the parties chain, so a customer group or a
 * customer can differ from the company: when the person issuing wishes, by default; a shop may require it at issue or
 * switch it off. Read before the invoice exists, so an invoice of its own sets nothing.
 */
final readonly class InvoiceReceiverSettings implements DeclaresSettings
{
    public const string RECEIVER = 'invoice.receiver';
    /** The module's key, as InvoicesModule declares it. */
    private const string MODULE = 'invoices';

    public function settings(): iterable
    {
        // The key and its choices written out, as the setting-labels gate reads them; ReceiverRule::from refuses any other on reading.
        yield new SettingDefinition('invoice.receiver', SettingType::Enum, 'optional', SettingChain::Parties, [SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer], 'settings.invoice.receiver', self::MODULE, choices: ['off', 'optional', 'required']);
    }

    /** Whether an invoice of this customer names who received its goods, as its customer, its group and its company say. */
    public static function ruleFor(ReadSetting $settings, Customer $customer): ReceiverRule
    {
        $context = new SettingContext($customer->getCompany(), customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());

        return ReceiverRule::from($settings->text($context, self::RECEIVER));
    }
}
