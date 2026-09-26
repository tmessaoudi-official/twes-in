<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

/** « Me prévenir » on a module that already ships: it is switched on, never waited for (409). */
final class ModuleAlreadyAvailable extends \RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct(\sprintf('%s is already available: switch it on instead.', $key));
    }
}
