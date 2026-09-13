<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Domain;

/** The chains a read walks, each from its most general level to its most specific (docs/SPEC.md § 3 Settings). */
enum SettingChain: string
{
    case Parties = 'parties';
    case Articles = 'articles';
    case Presentation = 'presentation';

    /** @return list<SettingLevel> most general first */
    public function levels(): array
    {
        return match ($this) {
            self::Parties => [SettingLevel::Platform, SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer, SettingLevel::Document],
            self::Articles => [SettingLevel::Platform, SettingLevel::Company, SettingLevel::ProductCategory, SettingLevel::Product, SettingLevel::DocumentLine],
            self::Presentation => [SettingLevel::Platform, SettingLevel::Company, SettingLevel::Role, SettingLevel::User],
        };
    }

    public function has(SettingLevel $level): bool
    {
        return \in_array($level, $this->levels(), true);
    }
}
