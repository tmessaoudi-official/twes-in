<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

/** The module needs modules the company has switched off. */
final class ModuleDependenciesDisabled extends \RuntimeException
{
    /** @param list<string> $modules the keys of the modules to switch on first */
    public function __construct(string $key, public readonly array $modules)
    {
        parent::__construct(\sprintf('%s needs these modules on first: %s.', $key, implode(', ', $modules)));
    }
}
