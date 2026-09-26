<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

/** A module that is only planned (docs/SPEC.md § 7, 2026-09-26 10:08): listed so the product shows whole, never switched. */
final class ModuleNotAvailable extends \RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct(\sprintf('%s is not available yet.', $key));
    }
}
