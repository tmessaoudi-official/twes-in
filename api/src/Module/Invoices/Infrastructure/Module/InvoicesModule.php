<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Module;

use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/**
 * Invoices, their credit notes and their payments (docs/SPEC.md § 3 Modules): an invoice goes to a customer and its lines
 * sell products. Delivery notes are not needed: a company invoices without them.
 */
final readonly class InvoicesModule implements DeclaresModule
{
    public const string KEY = 'invoices';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::KEY,
            'modules.invoices',
            [CustomersModule::KEY, ProductsModule::KEY],
            [InvoicePermission::READ, InvoicePermission::WRITE, InvoicePermission::ISSUE],
        );
    }
}
