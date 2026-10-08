<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Infrastructure\Module;

use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/**
 * « Export comptable »: the sales, payments and purchases journals and the VAT
 * summary of a period, as the files an accountant's software imports. It reads the invoices, so it needs them; the
 * purchases journal reads the expenses where the company keeps them. Its one permission is exporting.
 */
final readonly class AccountingExportModule implements DeclaresModule
{
    public const string KEY = 'accounting_export';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.accounting_export', [InvoicesModule::KEY], [AccountingExportPermission::EXPORT]);
    }
}
