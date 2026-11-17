<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Invoicing;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * What invoicing delivery notes of several customers at once does: the first note's customer and establishment are
 * invoiced and the others left out, as by default, or each customer and establishment gets a draft of its own. Declared
 * after the parties' pairs, so the settings page draws it on a row of its own.
 */
#[AsTaggedItem(priority: -160)]
final readonly class DeliveryNoteInvoicingSettings implements DeclaresSettings
{
    public const string MIXED_CUSTOMERS = 'delivery_note.mixed_customers_invoicing';
    public const string FIRST_CUSTOMER = 'first_customer';
    public const string INVOICE_EACH = 'invoice_each';
    /** The module's key, as DeliveryNotesModule declares it. */
    private const string MODULE = 'delivery_notes';

    public function settings(): iterable
    {
        // The key and its choices written out, as the setting-labels gate reads them.
        yield new SettingDefinition('delivery_note.mixed_customers_invoicing', SettingType::Enum, 'first_customer', SettingChain::Parties, [SettingLevel::Company], 'settings.delivery_note.mixed_customers_invoicing', self::MODULE, choices: ['first_customer', 'invoice_each']);
    }

    /** Whether the company drafts an invoice for each customer and establishment the notes chosen name. */
    public static function invoicesEachParty(ReadSetting $settings, Company $company): bool
    {
        return self::INVOICE_EACH === $settings->text(new SettingContext($company), self::MIXED_CUSTOMERS);
    }
}
