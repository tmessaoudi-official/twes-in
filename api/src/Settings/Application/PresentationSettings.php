<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * The presentation chain (docs/SPEC.md § 3 Settings): the look of the application, which a company or a role may
 * set as a default and each person may override, and each person's own list layouts and saved views. The web
 * registry (web/src/app/shared/settings/settings-registry.ts) declares the same keys and defaults for the pages
 * shown before anyone signs in.
 */
final readonly class PresentationSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    private const string LIST = '/^presentation\.list\.[a-z][a-z0-9-]*$/';
    private const string LIST_VIEWS = '/^presentation\.list\.[a-z][a-z0-9-]*\.views$/';

    public function settings(): iterable
    {
        $shared = [SettingLevel::Company, SettingLevel::Role, SettingLevel::User];
        $chain = SettingChain::Presentation;

        yield new SettingDefinition('presentation.accent', SettingType::Colour, '#1f6feb', $chain, $shared, 'settings.presentation.accent', self::MODULE);
        yield new SettingDefinition('presentation.scheme', SettingType::Enum, 'light', $chain, $shared, 'settings.presentation.scheme', self::MODULE, choices: ['light', 'dark']);
        yield new SettingDefinition('presentation.density', SettingType::Enum, 'comfortable', $chain, $shared, 'settings.presentation.density', self::MODULE, choices: ['comfortable', 'compact']);
        yield new SettingDefinition('presentation.sidebar', SettingType::Enum, 'expanded', $chain, $shared, 'settings.presentation.sidebar', self::MODULE, choices: ['expanded', 'rail']);
        yield new SettingDefinition('presentation.list.<id>', SettingType::Json, null, $chain, [SettingLevel::User], 'settings.presentation.list', self::MODULE, keyPattern: self::LIST);
        yield new SettingDefinition('presentation.list.<id>.views', SettingType::Json, null, $chain, [SettingLevel::User], 'settings.presentation.list_views', self::MODULE, keyPattern: self::LIST_VIEWS);
    }
}
