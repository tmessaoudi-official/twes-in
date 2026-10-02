<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Infrastructure\Module;

use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/**
 * Price lists (docs/SPEC.md § 7, 2026-09-20): per customer or group, quantity breaks and validity days, deciding a
 * line's unit price before any discount. It prices products for customers, so it needs both modules, and it
 * introduces no permission of its own: its resources check the products' (`product.read` to read, `product.write` to
 * change), since a price is a property of the product it is on.
 */
final readonly class PriceListsModule implements DeclaresModule
{
    public const string KEY = 'price_lists';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.price_lists', ['customers', 'products']);
    }
}
