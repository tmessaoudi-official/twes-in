<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Module;

use App\Module\Customers\Infrastructure\ApiPlatform\CustomerPermission;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/** Customers, their contacts and their groups (docs/SPEC.md § 3 Modules). */
final readonly class CustomersModule implements DeclaresModule
{
    public const string KEY = 'customers';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.customers', [], [CustomerPermission::READ, CustomerPermission::WRITE]);
    }
}
