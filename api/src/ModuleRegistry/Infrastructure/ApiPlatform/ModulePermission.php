<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\ApiPlatform;

/** Every member reads which modules the company has on; switching one is a company setting. */
final class ModulePermission
{
    public const string READ = 'company.read';
    public const string WRITE = 'company.settings';
}
