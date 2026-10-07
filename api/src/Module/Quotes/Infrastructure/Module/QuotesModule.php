<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\Module;

use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\Module\Quotes\Infrastructure\ApiPlatform\QuotePermission;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/** Quotes (devis): a quote goes to a customer, and an accepted one becomes an invoice. */
final readonly class QuotesModule implements DeclaresModule
{
    public const string KEY = 'quotes';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::KEY,
            'modules.quotes',
            [CustomersModule::KEY],
            [QuotePermission::READ, QuotePermission::WRITE],
        );
    }
}
