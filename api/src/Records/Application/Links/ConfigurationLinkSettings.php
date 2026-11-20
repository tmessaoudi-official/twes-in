<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Links;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * What deleting a record does to a link that only sets something up (a subcategory's category, a vendor's default
 * expense category, the references declared `cleared`): refuse the delete, as any use does and as by default, or empty
 * the link, said at once and written back by the restore where it is still empty. ConfigurationLinks reads it. Declared
 * among the parties' last settings, after when a draft offers its cancel (-207) and before the undo window (-210), on a
 * row of its own.
 */
#[AsTaggedItem(priority: -208)]
final readonly class ConfigurationLinkSettings implements DeclaresSettings
{
    public const string LINKS = 'records.configuration_links';
    public const string BLOCK_DELETE = 'block_delete';
    public const string CLEAR = 'clear';
    /** Not a module: the delete is the records' own. */
    private const string MODULE = 'core';

    public function settings(): iterable
    {
        // The key and its choices written out, as the setting-labels gate reads them.
        yield new SettingDefinition('records.configuration_links', SettingType::Enum, 'block_delete', SettingChain::Parties, [SettingLevel::Company], 'settings.records.configuration_links', self::MODULE, choices: ['block_delete', 'clear']);
    }
}
