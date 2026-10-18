<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\Module;

use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/** Orders to vendors (docs/SPEC.md § 3 Modules): an order goes to a vendor, orders products, and its goods arrive in stock. */
final readonly class PurchasesModule implements DeclaresModule
{
    public const string KEY = 'purchases';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::KEY,
            'modules.purchases',
            [InventoryModule::KEY, ProductsModule::KEY, VendorsModule::KEY],
            [PurchaseOrderPermission::READ, PurchaseOrderPermission::WRITE, PurchaseOrderPermission::RECEIVE],
        );
    }
}
