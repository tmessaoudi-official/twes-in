<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

/**
 * A module, declared once, by a service inside its own directory `src/Module/<Name>/`. Every resource under that
 * directory belongs to the module: switched off, they answer 404.
 */
interface DeclaresModule
{
    public function manifest(): ModuleManifest;
}
