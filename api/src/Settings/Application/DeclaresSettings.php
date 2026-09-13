<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\SettingDefinition;

/**
 * A module's settings, declared in code (docs/SPEC.md § 3 Settings). Every implementation is collected into the
 * catalogue, so a module adds its settings without touching the engine.
 */
interface DeclaresSettings
{
    /** @return iterable<SettingDefinition> */
    public function settings(): iterable;
}
