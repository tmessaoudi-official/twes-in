<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

/** A declared module and whether one company has it on. */
final readonly class ModuleView
{
    public function __construct(public ModuleManifest $manifest, public bool $enabled)
    {
    }
}
