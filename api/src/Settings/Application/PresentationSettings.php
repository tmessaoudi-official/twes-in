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
        yield new SettingDefinition('presentation.scheme', SettingType::Enum, 'auto', $chain, $shared, 'settings.presentation.scheme', self::MODULE, choices: ['auto', 'light', 'dark']);
        yield new SettingDefinition('presentation.density', SettingType::Enum, 'comfortable', $chain, $shared, 'settings.presentation.density', self::MODULE, choices: ['comfortable', 'compact']);
        yield new SettingDefinition('presentation.sidebar', SettingType::Enum, 'expanded', $chain, $shared, 'settings.presentation.sidebar', self::MODULE, choices: ['expanded', 'rail']);
        yield new SettingDefinition('presentation.sidebar-settings', SettingType::Enum, 'expanded', $chain, $shared, 'settings.presentation.sidebar_settings', self::MODULE, choices: ['expanded', 'rail']);
        yield new SettingDefinition('presentation.plan-labels', SettingType::Enum, 'code', $chain, $shared, 'settings.presentation.plan_labels', self::MODULE, choices: ['code', 'name', 'both']);
        yield new SettingDefinition('presentation.language', SettingType::Enum, 'fr', $chain, $shared, 'settings.presentation.language', self::MODULE, choices: ['fr', 'en']);
        // What customer view hides on screen (docs/SPEC.md § 7, 2026-09-23 slice 5): the company's choice alone, since a
        // customer standing at the counter is not one person's preference. What the API sends is product.cost.read's.
        yield new SettingDefinition('presentation.customer-view.cost', SettingType::Bool, true, $chain, [SettingLevel::Company], 'settings.presentation.customer_view_cost', self::MODULE);
        yield new SettingDefinition('presentation.customer-view.supplier-codes', SettingType::Bool, true, $chain, [SettingLevel::Company], 'settings.presentation.customer_view_supplier_codes', self::MODULE);
        // « Montrer ce qui arrive » (docs/SPEC.md § 7, 2026-09-25 17:22): the vision's parts not built yet, marked. The
        // company may hide them for everyone; a member for themself.
        yield new SettingDefinition('presentation.show-coming', SettingType::Bool, true, $chain, $shared, 'settings.presentation.show_coming', self::MODULE);
        // Each person's single-key shortcuts (docs/SPEC.md § 7, 2026-09-24 22:51, row 125): C, N, E and / until they choose.
        // Nothing but the person sets them, and the web reads each key defensively, as it reads a list layout.
        yield new SettingDefinition('presentation.shortcuts', SettingType::Json, null, $chain, [SettingLevel::User], 'settings.presentation.shortcuts', self::MODULE);
        // How dates and numbers are written, on screen and on printed documents (docs/SPEC.md § 7, 2026-09-25 12:45, row 130):
        // `auto` follows the language, which is what everything printed before these keys existed.
        yield new SettingDefinition('presentation.date-format', SettingType::Enum, 'auto', $chain, $shared, 'settings.presentation.date_format', self::MODULE, choices: ['auto', 'dmy', 'mdy', 'ymd', 'dmy-dots']);
        yield new SettingDefinition('presentation.number-format', SettingType::Enum, 'auto', $chain, $shared, 'settings.presentation.number_format', self::MODULE, choices: ['auto', 'space-comma', 'dot-comma', 'comma-dot']);
        // The menu sections a person folded (docs/SPEC.md § 7, 2026-09-26 12:05, row 152), as `<menu>.<section>`: each
        // person's own, remembered on every device; a section holding the current page opens whatever this says.
        yield new SettingDefinition('presentation.folded-sections', SettingType::Json, null, $chain, [SettingLevel::User], 'settings.presentation.folded_sections', self::MODULE);
        yield new SettingDefinition('presentation.list.<id>', SettingType::Json, null, $chain, [SettingLevel::User], 'settings.presentation.list', self::MODULE, keyPattern: self::LIST);
        yield new SettingDefinition('presentation.list.<id>.views', SettingType::Json, null, $chain, [SettingLevel::User], 'settings.presentation.list_views', self::MODULE, keyPattern: self::LIST_VIEWS);
    }
}
