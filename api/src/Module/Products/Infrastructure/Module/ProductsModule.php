<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Module;

use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/** Products and their categories (docs/SPEC.md § 3 Modules). */
final readonly class ProductsModule implements DeclaresModule
{
    public const string KEY = 'products';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.products', [], [ProductPermission::READ, ProductPermission::WRITE]);
    }
}
