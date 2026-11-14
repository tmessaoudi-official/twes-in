<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\FiscalInventory;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;

/** The keys sent with writes that add are forgotten after a day: no record of anything issued. */
final readonly class SharedFiscalRecords implements DeclaresFiscalRecords
{
    public function fiscalRecords(): array
    {
        return [];
    }

    public function notFiscal(): array
    {
        return [
            'submission_claim' => 'the key a write that adds was sent with, forgotten after a day; the write itself is counted where it landed',
        ];
    }
}
