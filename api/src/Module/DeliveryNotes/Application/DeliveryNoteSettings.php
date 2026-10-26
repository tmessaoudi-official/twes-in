<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Module\Customers\Domain\Model\Customer;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Settings\Application\DeclaresSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use App\Shared\Domain\ValueObject\ReceiverRule;

/**
 * The delivery notes module's settings, in the parties chain, so a customer group, a customer or a note can differ from
 * its company.
 */
final readonly class DeliveryNoteSettings implements DeclaresSettings
{
    /** The module's key, as DeliveryNotesModule declares it. */
    private const string MODULE = 'delivery_notes';
    public const string RECEIVER = 'delivery_note.receiver';

    public function settings(): iterable
    {
        $parties = [SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer, SettingLevel::Document];

        yield new SettingDefinition('delivery_note.show_prices', SettingType::Bool, true, SettingChain::Parties, $parties, 'settings.delivery_note.show_prices', self::MODULE);
        // The reception block, on by default (docs/SPEC.md § 7, 2026-09-24 22:51).
        yield new SettingDefinition('delivery_note.reception_block', SettingType::Bool, true, SettingChain::Parties, $parties, 'settings.delivery_note.reception_block', self::MODULE);
        // Who received the goods: asked when the person recording wishes, by default; a shop may require it or switch it off.
        // Read before the note exists, so a note of its own sets nothing.
        // The key and its choices written out, as the setting-labels gate reads them; ReceiverRule::from refuses any other on reading.
        yield new SettingDefinition('delivery_note.receiver', SettingType::Enum, 'optional', SettingChain::Parties, [SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer], 'settings.delivery_note.receiver', self::MODULE, choices: ['off', 'optional', 'required']);
    }

    /** Whether a note of this customer names who received its goods, as its customer, its group and its company say. */
    public static function receiverRule(ReadSetting $settings, DeliveryNote $note): ReceiverRule
    {
        return self::receiverRuleFor($settings, $note->getCustomer());
    }

    /** Whether a note of this customer names who received its goods, before the note exists. */
    public static function receiverRuleFor(ReadSetting $settings, Customer $customer): ReceiverRule
    {
        $context = new SettingContext($customer->getCompany(), customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());

        return ReceiverRule::from($settings->text($context, self::RECEIVER));
    }
}
