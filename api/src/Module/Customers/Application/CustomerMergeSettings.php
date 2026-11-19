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
 * Which main contact a merge of customers keeps when more than one of them has one: the kept customer's, the others no
 * longer main until the merge is undone, as by default, or none, the merge refused until one main contact is left among
 * them. Either way a customer has one main contact at most. Declared after the parties' pairs, so the settings page
 * draws it on a row of its own.
 */
#[AsTaggedItem(priority: -152)]
final readonly class CustomerMergeSettings implements DeclaresSettings
{
    public const string MAIN_CONTACT = 'customer.merge_main_contact';
    public const string KEPT_MAIN_WINS = 'kept_main_wins';
    public const string REFUSE_WHILE_BOTH_HAVE_ONE = 'refuse_while_both_have_one';
    private const string MODULE = 'customers';

    public function settings(): iterable
    {
        // The key and its choices written out, as the setting-labels gate reads them.
        yield new SettingDefinition('customer.merge_main_contact', SettingType::Enum, 'kept_main_wins', SettingChain::Parties, [SettingLevel::Company], 'settings.customer.merge_main_contact', self::MODULE, choices: ['kept_main_wins', 'refuse_while_both_have_one']);
    }
}
