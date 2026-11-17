<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Whether a draft whose party, the customer of an invoice, a quote or a delivery note or the vendor of a purchase order,
 * was deactivated since it was written still takes its number: issued as it stands, as by default, or refused until it
 * names an active one. A party merged away is refused whatever is chosen, and a credit note is issued whatever is
 * chosen, as it corrects what was issued. The documents' modules read the key and its choices as constants. Declared
 * after the parties' pairs, so the settings page draws it on a row of its own.
 */
#[AsTaggedItem(priority: -150)]
final readonly class PartyAtIssueSettings implements DeclaresSettings
{
    public const string INACTIVE_PARTY = 'document.inactive_party_at_issue';
    public const string ALLOWED = 'allowed';
    public const string REFUSED = 'refused';
    private const string MODULE = 'customers';

    public function settings(): iterable
    {
        // The key and its choices written out, as the setting-labels gate reads them.
        yield new SettingDefinition('document.inactive_party_at_issue', SettingType::Enum, 'allowed', SettingChain::Parties, [SettingLevel::Company], 'settings.document.inactive_party_at_issue', self::MODULE, choices: ['allowed', 'refused']);
    }
}
