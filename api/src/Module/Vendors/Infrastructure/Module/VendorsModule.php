<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Module;

use App\Module\Vendors\Infrastructure\ApiPlatform\VendorPermission;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/** Vendors, the people a company buys from (docs/SPEC.md § 3 Modules). Needs no other module. */
final readonly class VendorsModule implements DeclaresModule
{
    public const string KEY = 'vendors';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.vendors', [], [VendorPermission::READ, VendorPermission::WRITE]);
    }
}
