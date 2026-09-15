<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Module;

use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Inventory\Infrastructure\ApiPlatform\StockPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/**
 * Inventory (docs/SPEC.md § 3 Modules): stock of products per location. It needs products; delivery notes move its stock
 * when they are on too, and it works without them from receipts and counts.
 */
final readonly class InventoryModule implements DeclaresModule
{
    public const string KEY = MoveStockForDeliveryNotes::MODULE;

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.inventory', [ProductsModule::KEY], [StockPermission::READ, StockPermission::WRITE]);
    }
}
