<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalRecords;

/** The legal pages' versions, the platform's own: which terms were in force when. */
final readonly class LegalFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [
            FiscalRecords::kept('legal.texts', 'legal_text', dateColumn: 'published_on', companyColumn: null),
        ];
    }

    public function notFiscal(): array
    {
        return [];
    }
}
