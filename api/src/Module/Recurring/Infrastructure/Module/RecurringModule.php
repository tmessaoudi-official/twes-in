<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\Module;

use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/**
 * « Factures récurrentes »: an invoice drafted again on a schedule, for a person to issue. It copies invoices, so it
 * needs them, and is read and written under their permissions: what it makes is an invoice.
 */
final readonly class RecurringModule implements DeclaresModule
{
    public const string KEY = 'recurring';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.recurring', [InvoicesModule::KEY]);
    }
}
