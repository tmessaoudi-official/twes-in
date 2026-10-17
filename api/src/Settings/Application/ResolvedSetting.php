<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;

/** A setting as one context sees it: the value in force, where it comes from, and what each level holds. */
final readonly class ResolvedSetting
{
    /**
     * @param SettingLevel|null    $source   null when the default is in force
     * @param array<string, mixed> $explicit the values stored along the chain that still apply, by level, most general first
     * @param mixed                $default  what applies when nothing is stored: the country's, else the declared one
     * @param array<string, int>   $versions the version of each row stored along the chain, by level, which a save sends back
     */
    public function __construct(
        public string $key,
        public SettingDefinition $definition,
        public mixed $value,
        public ?SettingLevel $source,
        public array $explicit,
        public mixed $default,
        public array $versions,
    ) {
    }
}
