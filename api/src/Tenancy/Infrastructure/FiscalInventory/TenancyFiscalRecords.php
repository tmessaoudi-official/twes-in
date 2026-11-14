<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/**
 * The numbering series that handed out a number to a document the law keeps (a quote's number is no such document's),
 * and the support accesses an owner granted, kept as who was let in.
 */
final readonly class TenancyFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::fiscal('tenancy.numbering_series', 'numbering_series', "last_numbered_day IS NOT NULL AND document_type <> 'quote'"),
            FiscalRecords::kept('tenancy.support_grants', 'support_grant', dateColumn: 'starts_at'),
        ];
    }

    public function notFiscal(): array
    {
        return [
            'company' => 'the company itself: its status and the day its retention ends are printed with its inventory',
        ];
    }
}
