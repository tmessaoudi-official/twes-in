<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

/** One key a settings page changed, with the version its row held at that level when the page read it, 0 for none. */
final readonly class SettingChange
{
    public function __construct(public string $key, public mixed $value, public int $version)
    {
    }
}
