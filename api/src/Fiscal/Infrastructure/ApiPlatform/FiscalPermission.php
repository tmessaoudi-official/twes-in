<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\ApiPlatform;

/** The two permissions guarding a company's taxes and units; the built-in roles grant them (SeedPlatform). */
final class FiscalPermission
{
    public const string READ = 'fiscal.read';
    public const string WRITE = 'fiscal.write';
}
