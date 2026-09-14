<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Module;

use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\DeliveryNotePermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/** Delivery notes (docs/SPEC.md § 3 Modules): a note goes to a customer and its lines deliver products. */
final readonly class DeliveryNotesModule implements DeclaresModule
{
    public const string KEY = 'delivery_notes';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::KEY,
            'modules.delivery_notes',
            [CustomersModule::KEY, ProductsModule::KEY],
            [DeliveryNotePermission::READ, DeliveryNotePermission::WRITE, DeliveryNotePermission::VALIDATE],
        );
    }
}
