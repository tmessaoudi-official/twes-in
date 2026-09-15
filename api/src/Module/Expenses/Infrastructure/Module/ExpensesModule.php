<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Module;

use App\Module\Expenses\Infrastructure\ApiPlatform\ExpensePermission;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/** Expenses, their categories and the files they rest on (docs/SPEC.md § 3 Modules): an expense names a vendor. */
final readonly class ExpensesModule implements DeclaresModule
{
    public const string KEY = 'expenses';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.expenses', [VendorsModule::KEY], [ExpensePermission::READ, ExpensePermission::WRITE]);
    }
}
