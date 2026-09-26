<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

/** A planned module and how many companies asked to be told when it arrives: the operator's reading. */
final readonly class ModuleDemand
{
    public function __construct(public ModuleManifest $manifest, public int $companies)
    {
    }
}
