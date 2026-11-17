<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/** The files imported, kept under their digest with what each row became. */
final readonly class ImportFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::kept('import_export.runs', 'import_run', parts: ['import_run_record', 'import_run_removal'], dateColumn: 'at'),
        ];
    }

    public function notFiscal(): array
    {
        return [];
    }
}
