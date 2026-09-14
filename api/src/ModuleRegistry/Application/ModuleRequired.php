<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

/** Modules the company has on still need this one. */
final class ModuleRequired extends \RuntimeException
{
    /** @param list<string> $modules the keys of the enabled modules that need it */
    public function __construct(string $key, public readonly array $modules)
    {
        parent::__construct(\sprintf('%s is still needed by: %s.', $key, implode(', ', $modules)));
    }
}
