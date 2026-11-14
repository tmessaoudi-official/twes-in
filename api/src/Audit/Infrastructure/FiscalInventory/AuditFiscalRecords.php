<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/** The journal, which the application may not rewrite: kept with the company's data, no issued document itself. */
final readonly class AuditFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::kept('audit.journal', 'audit_log', dateColumn: 'at'),
        ];
    }

    public function notFiscal(): array
    {
        return [];
    }
}
