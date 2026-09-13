<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

/**
 * How code reads a business value that belongs in a setting: by a declared key, for a context. A key literal
 * passed here is checked against the catalogue by tests/Architecture/RegisteredSettingsTest.
 */
final readonly class ReadSetting
{
    public function __construct(private ResolveSettings $resolve)
    {
    }

    /** @throws UnknownSetting */
    public function value(SettingContext $context, string $key): mixed
    {
        return $this->resolve->one($context, $key)->value;
    }
}
